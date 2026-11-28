<?php

namespace App\Http\Controllers\Admin\Growth;

use App\Enums\GrowthCampaignStatus;
use App\Enums\MemberBadgeType;
use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\EarlyMember;
use App\Models\GrowthCampaign;
use App\Models\GrowthCampaignVersion;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use App\Services\Growth\EarlyMemberAndBadgeService;
use App\Services\Growth\GrowthConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GrowthCampaignsAndProgramsController extends Controller
{
    public function __construct(
        private readonly EarlyMemberAndBadgeService $badgeService,
        private readonly GrowthConfigService $config,
    ) {}

    public function index(): Response
    {
        $campaigns = GrowthCampaign::query()
            ->withCount(['participants', 'versions'])
            ->orderBy('id')
            ->get()
            ->map(static fn (GrowthCampaign $c): array => [
                'id' => $c->id,
                'slug' => $c->slug,
                'name' => $c->name,
                'description' => $c->description,
                'type' => $c->type,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'starts_at' => $c->starts_at?->toIso8601String(),
                'ends_at' => $c->ends_at?->toIso8601String(),
                'max_participants' => $c->max_participants,
                'current_participants' => $c->current_participants,
                'max_reward_days_budget' => $c->max_reward_days_budget,
                'used_reward_days_budget' => $c->used_reward_days_budget,
                'versions_count' => (int) ($c->versions_count ?? 0),
            ])
            ->values()
            ->all();

        $earlyMembers = EarlyMember::query()
            ->with('user:id,name,username,email_verified_at')
            ->orderByDesc('member_number')
            ->limit(50)
            ->get()
            ->map(static fn (EarlyMember $e): array => [
                'id' => $e->id,
                'member_number' => $e->member_number,
                'username' => $e->user?->username,
                'name' => $e->user?->name,
                'status' => $e->status,
                'premium_days_granted' => $e->premium_days_granted,
                'qualified_at' => $e->qualified_at->toIso8601String(),
                'revoke_reason' => $e->revoke_reason,
            ])
            ->values()
            ->all();

        $badges = UserBadge::query()
            ->with('user:id,name,username')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (UserBadge $b): array => [
                'id' => $b->id,
                'username' => $b->user?->username,
                'badge_type' => $b->badge_type,
                'grant_source' => $b->grant_source,
                'is_active' => $b->isActive(),
                'granted_at' => $b->granted_at->toIso8601String(),
                'expires_at' => $b->expires_at?->toIso8601String(),
                'revoke_reason' => $b->revoke_reason,
            ])
            ->values()
            ->all();

        $achievements = Achievement::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Achievement $a): array => [
                'id' => $a->id,
                'slug' => $a->slug,
                'name' => $a->name,
                'description' => $a->description,
                'criteria_type' => $a->criteria_type,
                'reward_premium_days' => $a->reward_premium_days,
                'grants_badge_type' => $a->grants_badge_type,
                'is_active' => $a->is_active,
                'unlocked_count' => UserAchievement::query()->where('achievement_id', $a->id)->where('status', 'unlocked')->count(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Growth/CampaignsAndPrograms', [
            'campaigns' => $campaigns,
            'earlyMembers' => $earlyMembers,
            'earlySummary' => [
                'enabled' => $this->config->bool('early_member_enabled', false),
                'max_slots' => $this->config->int('early_member_max_slots', 1000),
                'active_count' => EarlyMember::query()->where('status', 'active')->count(),
                'highest_number' => (int) (EarlyMember::query()->max('member_number') ?? 0),
            ],
            'badges' => $badges,
            'achievements' => $achievements,
            'campaignStatuses' => array_map(static fn (GrowthCampaignStatus $s): array => [
                'value' => $s->value,
                'label' => $s->label(),
            ], GrowthCampaignStatus::cases()),
            'badgeTypes' => array_map(
                static fn (MemberBadgeType $b): array => [
                    'value' => $b->value,
                    'label' => $b->label(),
                ],
                [
                    MemberBadgeType::EarlyMember,
                    MemberBadgeType::FoundingMember,
                    MemberBadgeType::ReferralAmbassador,
                    MemberBadgeType::CommunityBuilder,
                ],
            ),
        ]);
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:140'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['launch', 'early_member', 'referral', 'premium_reward', 'community_growth', 'seasonal', 'founding_member'])],
            'status' => ['required', Rule::in(array_map(static fn (GrowthCampaignStatus $s): string => $s->value, GrowthCampaignStatus::cases()))],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_participants' => ['nullable', 'integer', 'min:1', 'max:500000'],
            'max_reward_days_budget' => ['nullable', 'integer', 'min:1', 'max:5000000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $campaign = GrowthCampaign::query()->create([
            'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(4)),
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'status' => GrowthCampaignStatus::from($validated['status']),
            'starts_at' => isset($validated['starts_at']) ? CarbonImmutable::parse($validated['starts_at']) : null,
            'ends_at' => isset($validated['ends_at']) ? CarbonImmutable::parse($validated['ends_at']) : null,
            'max_participants' => $validated['max_participants'] ?? null,
            'max_reward_days_budget' => $validated['max_reward_days_budget'] ?? null,
            'created_by' => $actor->id,
        ]);

        $version = GrowthCampaignVersion::query()->create([
            'campaign_id' => $campaign->id,
            'version_number' => 1,
            'qualification_rules' => $campaign->qualification_rules,
            'reward_config' => $campaign->reward_config,
            'notes' => 'Initial version',
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        $campaign->forceFill(['active_version_id' => $version->id])->save();

        $this->config->logActivity('growth.campaign.created', $actor, null, GrowthCampaign::class, $campaign->id, [
            'slug' => $campaign->slug,
            'status' => $campaign->status->value,
        ]);

        return back()->with('status', __('Growth campaign created cleanly.'));
    }

    public function updateCampaign(Request $request, GrowthCampaign $campaign): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_map(static fn (GrowthCampaignStatus $s): string => $s->value, GrowthCampaignStatus::cases()))],
            'max_participants' => ['nullable', 'integer', 'min:1', 'max:500000'],
            'max_reward_days_budget' => ['nullable', 'integer', 'min:1', 'max:5000000'],
            'notes' => ['nullable', 'string', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $campaign->forceFill([
            'status' => GrowthCampaignStatus::from($validated['status']),
            'max_participants' => array_key_exists('max_participants', $validated) ? $validated['max_participants'] : $campaign->max_participants,
            'max_reward_days_budget' => array_key_exists('max_reward_days_budget', $validated) ? $validated['max_reward_days_budget'] : $campaign->max_reward_days_budget,
        ])->save();

        $nextVer = ((int) GrowthCampaignVersion::query()->where('campaign_id', $campaign->id)->max('version_number')) + 1;
        $ver = GrowthCampaignVersion::query()->create([
            'campaign_id' => $campaign->id,
            'version_number' => $nextVer,
            'qualification_rules' => $campaign->qualification_rules,
            'reward_config' => $campaign->reward_config,
            'notes' => $validated['notes'] ?? "Status updated to {$campaign->status->value}",
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        $campaign->forceFill(['active_version_id' => $ver->id])->save();

        $this->config->logActivity('growth.campaign.updated', $actor, null, GrowthCampaign::class, $campaign->id, [
            'status' => $campaign->status->value,
            'version' => $nextVer,
        ]);

        return back()->with('status', __('Campaign updated and versioned (v:ver).', ['ver' => $nextVer]));
    }

    public function enrollEarlyMember(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'exists:users,username'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->where('username', $validated['username'])->firstOrFail();

        $enrolled = $this->badgeService->enrollEarlyMemberIfEligible($target, $actor, true);
        if ($enrolled === null) {
            return back()->withErrors(['username' => __('Member is ineligible (must be active verified human account and within slot limit).')]);
        }

        return back()->with('status', __('Enrolled @:user as Early Member #:num.', [
            'user' => $target->username,
            'num' => $enrolled->member_number,
        ]));
    }

    public function revokeEarlyMember(Request $request, EarlyMember $earlyMember): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->badgeService->revokeEarlyMember($earlyMember, $validated['reason'], $actor);

        return back()->with('status', __('Early Member status and promotional reward revoked (purchased Premium preserved).'));
    }

    public function grantBadge(Request $request): RedirectResponse
    {
        $allowedGrowthBadges = [
            MemberBadgeType::EarlyMember->value,
            MemberBadgeType::FoundingMember->value,
            MemberBadgeType::ReferralAmbassador->value,
            MemberBadgeType::CommunityBuilder->value,
        ];

        $validated = $request->validate([
            'username' => ['required', 'string', 'exists:users,username'],
            'badge_type' => ['required', Rule::in($allowedGrowthBadges)],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->where('username', $validated['username'])->firstOrFail();

        $this->badgeService->grantBadge(
            user: $target,
            type: MemberBadgeType::from($validated['badge_type']),
            source: $validated['source'] ?? 'admin_grant',
            actor: $actor,
        );

        return back()->with('status', __('Member badge granted cleanly.'));
    }

    public function revokeBadge(Request $request, UserBadge $badge): RedirectResponse
    {
        $badgeType = MemberBadgeType::tryFrom((string) $badge->badge_type);
        if ($badgeType !== null && $badgeType->isAuthority()) {
            abort(403, __('Authority badges must be revoked via Super Admin Badge Management.'));
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->badgeService->revokeBadge($badge, $validated['reason'], $actor);

        return back()->with('status', __('Member badge revoked.'));
    }

    public function toggleAchievement(Request $request, Achievement $achievement): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $achievement->forceFill(['is_active' => ! $achievement->is_active])->save();

        $this->config->logActivity('growth.achievement.toggled', $actor, null, Achievement::class, $achievement->id, [
            'is_active' => $achievement->is_active,
        ]);

        return back()->with('status', __('Achievement status updated.'));
    }
}
