<?php

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\EarlyMember;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\Growth\EarlyMemberAndBadgeService;
use App\Services\Growth\GrowthConfigService;
use App\Services\Growth\OnboardingAndActivationService;
use App\Services\Growth\ReferralService;
use App\Services\Growth\RetentionAndDigestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referralService,
        private readonly OnboardingAndActivationService $onboardingService,
        private readonly RetentionAndDigestService $retentionService,
        private readonly EarlyMemberAndBadgeService $badgeService,
        private readonly GrowthConfigService $config,
    ) {}

    /**
     * Public Referral Landing Redirect (/r/{code}) — validates code, tracks human visit once,
     * attaches First-Touch attribution cookie (30d default), and sends X-Robots-Tag: noindex, nofollow
     * so referral links NEVER create duplicate SEO URLs.
     */
    public function visit(Request $request, string $code): RedirectResponse
    {
        $validCode = $this->referralService->recordVisit($code, $request);
        $nextPath = $this->referralService->sanitizeInternalReturnPath($request->query('next'));
        $target = $nextPath ?? route('register', [], false);

        $response = redirect()->to($target)
            ->header('X-Robots-Tag', 'noindex, nofollow');

        if ($validCode !== null && ! $request->hasCookie(ReferralService::COOKIE_NAME)) {
            $days = max(1, min(90, $this->config->int('referral_attribution_days', 30)));
            $cookie = Cookie::make(
                name: ReferralService::COOKIE_NAME,
                value: $validCode->code,
                minutes: $days * 1440,
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: true,
                raw: false,
                sameSite: 'lax',
            );
            $response->withCookie($cookie);
        }

        return $response;
    }

    /**
     * Member Referral & Growth Hub (/referral).
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isHumanMember()) {
            abort(403, __('AI Agents and system accounts cannot participate in the Referral Program.'));
        }

        $this->badgeService->evaluateAchievements($user);

        $earlyRecord = EarlyMember::query()->where('user_id', $user->id)->where('status', 'active')->first();
        $earlyMax = max(1, $this->config->int('early_member_max_slots', 1000));
        $earlyCount = EarlyMember::query()->where('status', 'active')->count();

        $unlockedIds = UserAchievement::query()
            ->where('user_id', $user->id)
            ->where('status', 'unlocked')
            ->pluck('unlocked_at', 'achievement_id')
            ->all();

        $achievements = Achievement::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Achievement $a): array => [
                'id' => $a->id,
                'slug' => $a->slug,
                'name' => $a->name,
                'description' => $a->description,
                'icon' => $a->icon,
                'color' => $a->color,
                'unlocked' => array_key_exists($a->id, $unlockedIds),
                'unlocked_at' => isset($unlockedIds[$a->id]) ? $unlockedIds[$a->id]->toIso8601String() : null,
            ])
            ->values()
            ->all();

        $badges = $this->badgeService->activeBadgesForUsers([$user->id])[$user->id] ?? [];

        return Inertia::render('growth/Referral', [
            'referral' => $this->referralService->memberDashboardPayload($user),
            'earlyMember' => [
                'enabled' => $this->config->bool('early_member_enabled', false),
                'enrolled' => $earlyRecord !== null,
                'member_number' => $earlyRecord?->member_number,
                'premium_days_granted' => $earlyRecord !== null ? $earlyRecord->premium_days_granted : 0,
                'total_claimed' => $earlyCount,
                'max_slots' => $earlyMax,
            ],
            'badges' => $badges,
            'achievements' => $achievements,
            'notificationPreferences' => $this->retentionService->preferencesFor($user),
        ]);
    }

    /**
     * Personalized New Member Onboarding (/onboarding).
     */
    public function onboarding(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('growth/Onboarding', $this->onboardingService->onboardingPayload($user));
    }

    public function updateOnboarding(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'selected_locale' => ['nullable', 'string', 'max:16'],
            'selected_interests' => ['nullable', 'array', 'max:12'],
            'selected_interests.*' => ['string', 'max:60'],
            'current_step' => ['nullable', 'string', 'max:36'],
            'complete' => ['sometimes', 'boolean'],
            'skip' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $progress = $this->onboardingService->updateProgress($user, $validated);

        if (! empty($validated['complete']) || ! empty($validated['skip'])) {
            $target = $this->referralService->sanitizeInternalReturnPath($progress->return_to_url) ?? route('home', [], false);

            return redirect()->to($target)->with('status', __('Welcome to SyncViet! Your feed preferences are saved.'));
        }

        return back()->with('status', __('Onboarding preferences saved.'));
    }

    public function updateNotificationPreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weekly_digest' => ['required', 'boolean'],
            'reengagement' => ['required', 'boolean'],
            'referral_updates' => ['required', 'boolean'],
            'community_updates' => ['required', 'boolean'],
            'marketing_emails' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->retentionService->updatePreferences($user, $validated);

        return back()->with('status', __('Notification and digest preferences saved.'));
    }

    /**
     * Signed one-click unsubscribe endpoint for digests/re-engagement/marketing emails.
     */
    public function unsubscribe(Request $request, User $user, string $channel): RedirectResponse
    {
        $updates = match ($channel) {
            'weekly_digest' => ['weekly_digest' => false],
            'reengagement' => ['reengagement' => false],
            'marketing' => ['marketing_emails' => false],
            default => ['weekly_digest' => false, 'reengagement' => false, 'marketing_emails' => false],
        };

        $this->retentionService->updatePreferences($user, $updates);

        return redirect()->route('home')->with('status', __('Your notification preferences have been updated (unsubscribed).'));
    }
}
