<?php

namespace App\Services\Growth;

use App\Enums\ActivationMilestone;
use App\Enums\ReferralStatus;
use App\Models\GrowthFraudReview;
use App\Models\OnboardingProgress;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\ReferralQualificationEvent;
use App\Models\ReferralReward;
use App\Models\ReferralRewardRule;
use App\Models\ReferralVisit;
use App\Models\User;
use App\Models\UserActivationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public const COOKIE_NAME = 'sv_ref_code';

    public const SESSION_CODE_KEY = 'syncviet.growth.referral_code';

    public const SESSION_RETURN_KEY = 'syncviet.growth.return_to';

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly GrowthConfigService $config,
        private readonly FraudDetectionService $fraud,
        private readonly RewardEngineService $rewardEngine,
    ) {}

    /**
     * Generate or fetch a unique, non-sequential, PII-free referral code for an eligible human account.
     */
    public function getOrCreateCode(User $user): ReferralCode
    {
        if (! $user->isHumanMember()) {
            throw ValidationException::withMessages([
                'referral' => [__('AI Agents, system accounts, and test accounts cannot participate in the Referral Program.')],
            ]);
        }

        $existing = ReferralCode::query()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $code = $this->generateRandomCode();
            if (! ReferralCode::query()->where('code', $code)->exists()) {
                $created = ReferralCode::query()->create([
                    'user_id' => $user->id,
                    'code' => $code,
                    'is_active' => true,
                ]);

                $this->config->logActivity('growth.referral_code.created', $user, $user->id, ReferralCode::class, $created->id, [
                    'code' => $created->code,
                ]);

                return $created;
            }
        }

        throw ValidationException::withMessages([
            'referral' => [__('Unable to allocate a unique referral code right now. Please retry.')],
        ]);
    }

    /**
     * Validate an incoming referral code visit, skip bots/crawlers, deduplicate visits, and store First-Touch attribution.
     */
    public function recordVisit(string $rawCode, Request $request): ?ReferralCode
    {
        if (! $this->config->bool('referral_enabled', true)) {
            return null;
        }

        $code = strtoupper(trim($rawCode));
        if (! preg_match('/^SV-[A-Z0-9]{6,10}$/', $code)) {
            return null;
        }

        /** @var ReferralCode|null $refCode */
        $refCode = ReferralCode::query()
            ->with('user:id,account_type,is_test_account,status')
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($refCode === null || $refCode->user === null || ! $refCode->user->isHumanMember() || ! $refCode->user->isActive()) {
            return null;
        }

        $ua = strtolower((string) $request->userAgent());
        $isBot = $ua === '' || (bool) preg_match('/bot|crawler|spider|slurp|googlebot|bingbot|facebookexternalhit|curl|wget|python-requests/i', $ua);

        // Do NOT create infinite visit tracking records from bots or crawlers
        if ($isBot) {
            return $refCode;
        }

        // First-touch attribution: preserve existing valid session/cookie referral code if already set
        if ($request->hasSession() && ! $request->session()->has(self::SESSION_CODE_KEY)) {
            $request->session()->put(self::SESSION_CODE_KEY, $refCode->code);
        }

        $safeReturn = $this->sanitizeInternalReturnPath($request->query('next'));
        if ($safeReturn !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_RETURN_KEY, $safeReturn);
        }

        $sessionSeed = $request->hasSession() ? $request->session()->getId() : (string) $request->ip();
        $visitorHash = hash('sha256', $sessionSeed.'|'.now()->toDateString().'|'.Str::limit($ua, 80, ''));
        $retentionDays = max(7, $this->config->int('referral_visit_retention_days', 90));

        $inserted = ReferralVisit::query()->firstOrCreate(
            [
                'referral_code_id' => $refCode->id,
                'visitor_hash' => $visitorHash,
            ],
            [
                'ip_hash' => $this->fraud->hashIp($request->ip()),
                'landing_path' => Str::limit('/'.ltrim($request->path(), '/'), 250, ''),
                'referer_host' => is_string($request->headers->get('referer'))
                    ? Str::limit((string) parse_url((string) $request->headers->get('referer'), PHP_URL_HOST), 150, '')
                    : null,
                'locale' => app()->getLocale(),
                'is_bot' => false,
                'visited_at' => now(),
                'expires_at' => now()->addDays($retentionDays),
            ],
        );

        if ($inserted->wasRecentlyCreated) {
            $refCode->increment('visits_count');
        }

        return $refCode;
    }

    /**
     * First-touch Referral Attribution during new human account registration.
     * - Never overwrites an already attributed user.
     * - Blocks self-referral, AI Agents, and referral loops.
     * - Sets verification hold window (`eligible_at`) instead of rewarding immediately on signup.
     */
    public function attributeRegistration(User $referee, ?Request $request = null, ?string $explicitCode = null): ?Referral
    {
        if (! $this->config->bool('referral_enabled', true)) {
            return null;
        }

        if (! $referee->isHumanMember()) {
            return null;
        }

        // Strict First-Touch: never overwrite or allow retroactive referral claiming
        if ($referee->referred_by_user_id !== null || Referral::query()->where('referee_id', $referee->id)->exists()) {
            return null;
        }

        $rawCode = null;
        if ($request !== null) {
            if ($request->hasSession() && is_string($request->session()->get(self::SESSION_CODE_KEY))) {
                $rawCode = (string) $request->session()->get(self::SESSION_CODE_KEY);
            } elseif (is_string($request->cookie(self::COOKIE_NAME))) {
                $rawCode = (string) $request->cookie(self::COOKIE_NAME);
            }
        }

        if ($rawCode === null && is_string($explicitCode) && trim($explicitCode) !== '') {
            $rawCode = trim($explicitCode);
        }

        if ($rawCode === null || trim($rawCode) === '') {
            return null;
        }

        $normalizedCode = strtoupper(trim($rawCode));

        /** @var ReferralCode|null $refCode */
        $refCode = ReferralCode::query()
            ->with('user')
            ->where('code', $normalizedCode)
            ->where('is_active', true)
            ->first();

        if ($refCode === null || $refCode->user === null) {
            return null;
        }

        $referrer = $refCode->user;
        $ipHash = $request !== null ? $this->fraud->hashIp($request->ip()) : null;
        $risk = $this->fraud->evaluateReferral($referrer, $referee, $ipHash);

        // Hard-rejected attempts (self-referral, loop, non-human) never link as valid pending referrals
        if ($risk['hard_reject']) {
            $this->config->logActivity('growth.referral.blocked', $referee, $referrer->id, ReferralCode::class, $refCode->id, [
                'reason' => $risk['hard_reason'],
                'signals' => $risk['signals'],
            ]);

            return null;
        }

        $campaign = $this->rewardEngine->activeReferralCampaign();
        $verificationHours = max(0, $this->config->int('referral_verification_hours', 72));
        $initialStatus = $risk['needs_manual_review'] ? ReferralStatus::UnderReview : ReferralStatus::Pending;

        $referral = DB::transaction(function () use ($referrer, $referee, $refCode, $campaign, $ipHash, $risk, $verificationHours, $initialStatus, $request): Referral {
            $ref = Referral::query()->create([
                'referrer_id' => $referrer->id,
                'referee_id' => $referee->id,
                'referral_code_id' => $refCode->id,
                'campaign_id' => $campaign?->id,
                'campaign_version_id' => $campaign?->active_version_id,
                'status' => $initialStatus,
                'status_reason' => $risk['needs_manual_review']
                    ? 'Flagged for manual review based on multi-signal velocity/risk checks.'
                    : 'Awaiting email verification, onboarding/activity, and verification window.',
                'attribution_source' => 'referral',
                'landing_path' => $request !== null && $request->hasSession() ? (string) ($request->session()->get(self::SESSION_RETURN_KEY) ?? '/register') : '/register',
                'locale' => app()->getLocale(),
                'ip_hash' => $ipHash,
                'risk_score' => $risk['score'],
                'risk_signals' => $risk['signals'],
                'eligible_at' => now()->addHours($verificationHours),
            ]);

            ReferralQualificationEvent::query()->create([
                'referral_id' => $ref->id,
                'from_status' => null,
                'to_status' => $initialStatus->value,
                'reason' => $ref->status_reason,
                'metadata' => ['risk_score' => $risk['score'], 'signals' => $risk['signals']],
                'created_at' => now(),
            ]);

            $refCode->increment('registrations_count');

            $updates = ['referred_by_user_id' => $referrer->id];
            if ($referee->acquisition_source === null || $referee->acquisition_source === '') {
                $updates['acquisition_source'] = 'referral';
                $updates['acquisition_locale'] = app()->getLocale();
            }
            $referee->forceFill($updates)->saveQuietly();

            if ($risk['needs_manual_review']) {
                GrowthFraudReview::query()->create([
                    'referral_id' => $ref->id,
                    'user_id' => $referrer->id,
                    'risk_score' => $risk['score'],
                    'signals' => $risk['signals'],
                    'status' => 'pending',
                ]);
            }

            return $ref;
        });

        $this->config->logActivity('growth.referral.attributed', $referee, $referrer->id, Referral::class, $referral->id, [
            'status' => $referral->status->value,
            'risk_score' => $referral->risk_score,
        ]);

        return $referral;
    }

    /**
     * Evaluate pending referrals whose verification window (`eligible_at`) has elapsed.
     *
     * @return array{checked: int, qualified: int, under_review: int, rejected: int, rewards_granted: int}
     */
    public function processPendingQualifications(int $batchLimit = 100): array
    {
        $checked = 0;
        $qualified = 0;
        $underReview = 0;
        $rejected = 0;
        $rewardsGranted = 0;

        $requireVerified = $this->config->bool('referral_require_email_verified', true);
        $requireActivity = $this->config->bool('referral_require_onboarding_or_activity', true);

        $pending = Referral::query()
            ->with(['referrer', 'referee'])
            ->where('status', ReferralStatus::Pending->value)
            ->where(static function ($q): void {
                $q->whereNull('eligible_at')->orWhere('eligible_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($batchLimit)
            ->get();

        foreach ($pending as $ref) {
            $checked++;
            $referrer = $ref->referrer;
            $referee = $ref->referee;

            if ($referrer === null || $referee === null || ! $referee->isHumanMember()) {
                $this->transitionStatus($ref, ReferralStatus::Rejected, 'Account no longer eligible for referral qualification.');
                $rejected++;

                continue;
            }

            if (! $referee->isActive()) {
                $this->transitionStatus($ref, ReferralStatus::Rejected, 'Referred account is suspended, banned, or deactivated.');
                $rejected++;

                continue;
            }

            if ($requireVerified && ! $referee->hasVerifiedEmail()) {
                continue; // Still waiting for email verification
            }

            if ($requireActivity && ! $this->hasRealActivityOrOnboarding($referee)) {
                continue; // Still waiting for meaningful activity or onboarding
            }

            // Re-run fraud check before qualifying
            $risk = $this->fraud->evaluateReferral($referrer, $referee, $ref->ip_hash);
            if ($risk['hard_reject']) {
                $this->transitionStatus($ref, ReferralStatus::Rejected, $risk['hard_reason'] ?? 'Failed fraud eligibility checks.');
                $rejected++;

                continue;
            }

            if ($risk['needs_manual_review']) {
                $ref->forceFill(['risk_score' => $risk['score'], 'risk_signals' => $risk['signals']])->save();
                $this->transitionStatus($ref, ReferralStatus::UnderReview, 'Moved to manual fraud review prior to qualification.');
                GrowthFraudReview::query()->firstOrCreate(
                    ['referral_id' => $ref->id],
                    [
                        'user_id' => $referrer->id,
                        'risk_score' => $risk['score'],
                        'signals' => $risk['signals'],
                        'status' => 'pending',
                    ],
                );
                $underReview++;

                continue;
            }

            $ref->forceFill(['qualified_at' => now()])->save();
            $this->transitionStatus($ref, ReferralStatus::Qualified, 'Verified email, activity/onboarding, and verification window satisfied.');
            ReferralCode::query()->whereKey($ref->referral_code_id)->increment('qualified_count');
            $qualified++;

            $grantedList = $this->rewardEngine->evaluateAndGrantForUser($referrer);
            $rewardsGranted += count($grantedList);
        }

        return [
            'checked' => $checked,
            'qualified' => $qualified,
            'under_review' => $underReview,
            'rejected' => $rejected,
            'rewards_granted' => $rewardsGranted,
        ];
    }

    public function adminReviewReferral(Referral $referral, string $decision, string $reason, User $actor): Referral
    {
        $cleanReason = trim($reason) !== '' ? trim($reason) : "Reviewed by {$actor->username}";

        $next = match ($decision) {
            'approve', 'qualified' => ReferralStatus::Qualified,
            'reject', 'rejected' => ReferralStatus::Rejected,
            'revoke', 'revoked' => ReferralStatus::Revoked,
            default => throw ValidationException::withMessages(['decision' => [__('Invalid referral review decision.')]]),
        };

        $wasQualified = $referral->status->countsTowardMilestone();
        $this->transitionStatus($referral, $next, $cleanReason, $actor);

        $referral->forceFill([
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'qualified_at' => $next === ReferralStatus::Qualified ? ($referral->qualified_at ?? now()) : $referral->qualified_at,
            'revoked_at' => $next === ReferralStatus::Revoked ? now() : $referral->revoked_at,
            'revoked_reason' => $next === ReferralStatus::Revoked ? $cleanReason : $referral->revoked_reason,
        ])->save();

        if ($next === ReferralStatus::Qualified && ! $wasQualified) {
            ReferralCode::query()->whereKey($referral->referral_code_id)->increment('qualified_count');
            if ($referral->referrer !== null) {
                $this->rewardEngine->evaluateAndGrantForUser($referral->referrer, $actor);
            }
        }

        if ($next === ReferralStatus::Revoked && $wasQualified) {
            ReferralCode::query()->whereKey($referral->referral_code_id)->where('qualified_count', '>', 0)->decrement('qualified_count');
        }

        GrowthFraudReview::query()
            ->where('referral_id', $referral->id)
            ->update([
                'status' => $next === ReferralStatus::Qualified ? 'approved' : 'rejected',
                'decision_note' => $cleanReason,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

        $this->config->logActivity("growth.referral.{$next->value}", $actor, $referral->referrer_id, Referral::class, $referral->id, [
            'reason' => $cleanReason,
        ]);

        return $referral->fresh() ?? $referral;
    }

    /**
     * Member-facing /referral dashboard payload (strictly hides referee PII such as email/IP!).
     *
     * @return array<string, mixed>
     */
    public function memberDashboardPayload(User $user): array
    {
        $codeModel = $this->getOrCreateCode($user);
        $baseUrl = rtrim((string) config('syncviet.seo.canonical_base_url', config('app.url', 'https://sysnoxis.com')), '/');
        $locale = app()->getLocale();
        $referralLink = ($locale !== '' && $locale !== 'en')
            ? "{$baseUrl}/{$locale}/r/{$codeModel->code}"
            : "{$baseUrl}/r/{$codeModel->code}";

        $referrals = Referral::query()
            ->with('referee:id,name,username,created_at')
            ->where('referrer_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get();

        $totalReferred = Referral::query()->where('referrer_id', $user->id)->count();
        $pendingCount = Referral::query()->where('referrer_id', $user->id)->whereIn('status', [ReferralStatus::Pending->value, ReferralStatus::UnderReview->value])->count();
        $qualifiedCount = Referral::query()->where('referrer_id', $user->id)->whereIn('status', [ReferralStatus::Qualified->value, ReferralStatus::Rewarded->value])->count();
        $rejectedCount = Referral::query()->where('referrer_id', $user->id)->whereIn('status', [ReferralStatus::Rejected->value, ReferralStatus::Revoked->value])->count();

        $rules = ReferralRewardRule::query()
            ->where('is_active', true)
            ->orderBy('required_qualified_referrals')
            ->orderBy('sort_order')
            ->get();

        $rewards = ReferralReward::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(30)
            ->get();

        $claimedRuleIds = $rewards
            ->where('status', 'granted')
            ->pluck('rule_id')
            ->filter()
            ->all();

        $nextRule = $rules->first(static fn (ReferralRewardRule $r): bool => $r->required_qualified_referrals > $qualifiedCount);
        $totalDaysEarned = (int) $rewards->where('status', 'granted')->sum('days_granted');

        return [
            'enabled' => $this->config->bool('referral_enabled', true),
            'verificationHours' => $this->config->int('referral_verification_hours', 72),
            'attributionDays' => $this->config->int('referral_attribution_days', 30),
            'code' => $codeModel->code,
            'isCodeActive' => $codeModel->is_active,
            'referralLink' => $referralLink,
            'stats' => [
                'visits' => $codeModel->visits_count,
                'total_referred' => $totalReferred,
                'pending' => $pendingCount,
                'qualified' => $qualifiedCount,
                'rejected' => $rejectedCount,
                'premium_days_earned' => $totalDaysEarned,
            ],
            'nextMilestone' => $nextRule === null ? null : [
                'name' => $nextRule->name,
                'required' => $nextRule->required_qualified_referrals,
                'remaining' => max(0, $nextRule->required_qualified_referrals - $qualifiedCount),
                'cumulative_days' => $nextRule->cumulative_premium_days,
                'progress_percent' => min(100, (int) round(($qualifiedCount / max(1, $nextRule->required_qualified_referrals)) * 100)),
            ],
            'milestones' => $rules->map(static fn (ReferralRewardRule $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'required_qualified_referrals' => $r->required_qualified_referrals,
                'cumulative_premium_days' => $r->cumulative_premium_days,
                'reached' => $qualifiedCount >= $r->required_qualified_referrals,
                'claimed' => in_array($r->id, $claimedRuleIds, true),
            ])->values()->all(),
            'referrals' => $referrals->map(static fn (Referral $r): array => [
                'id' => $r->id,
                // Privacy-preserving masked handle ONLY (zero email/IP!)
                'referee_display' => $r->referee !== null ? '@'.$r->referee->username : 'Member',
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'status_reason' => $r->status_reason,
                'eligible_at' => $r->eligible_at?->toIso8601String(),
                'qualified_at' => $r->qualified_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
            'rewardHistory' => $rewards->map(static fn (ReferralReward $rw): array => [
                'id' => $rw->id,
                'milestone_referrals' => $rw->milestone_referrals,
                'days_granted' => $rw->days_granted,
                'cumulative_days_target' => $rw->cumulative_days_target,
                'source' => $rw->source,
                'status' => $rw->status,
                'note' => $rw->note,
                'granted_at' => $rw->granted_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function sanitizeInternalReturnPath(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        // Strictly allow relative internal paths only (blocks https://evil.com, //evil.com, javascript:)
        if ($trimmed === '' || ! str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//') || str_contains($trimmed, '\\')) {
            return null;
        }

        return Str::limit($trimmed, 240, '');
    }

    public function cleanupExpiredVisits(): int
    {
        return ReferralVisit::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function transitionStatus(Referral $referral, ReferralStatus $to, string $reason, ?User $actor = null): void
    {
        $from = $referral->status->value;
        $referral->forceFill([
            'status' => $to,
            'status_reason' => Str::limit($reason, 250, ''),
        ])->save();

        ReferralQualificationEvent::query()->create([
            'referral_id' => $referral->id,
            'from_status' => $from,
            'to_status' => $to->value,
            'reason' => Str::limit($reason, 250, ''),
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function hasRealActivityOrOnboarding(User $user): bool
    {
        if (OnboardingProgress::query()->where('user_id', $user->id)->whereNotNull('completed_at')->exists()) {
            return true;
        }

        return UserActivationEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('milestone', [
                ActivationMilestone::InterestsSelected->value,
                ActivationMilestone::FirstCommunityJoined->value,
                ActivationMilestone::FirstInteraction->value,
                ActivationMilestone::FirstPostPublished->value,
            ])
            ->exists();
    }

    private function generateRandomCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $suffix = '';

        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, $max)];
        }

        return 'SV-'.$suffix;
    }
}
