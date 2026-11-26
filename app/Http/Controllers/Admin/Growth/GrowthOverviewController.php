<?php

namespace App\Http\Controllers\Admin\Growth;

use App\Http\Controllers\Controller;
use App\Models\GrowthActivityLog;
use App\Models\User;
use App\Services\Growth\GrowthAnalyticsService;
use App\Services\Growth\GrowthConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GrowthOverviewController extends Controller
{
    public function __construct(
        private readonly GrowthAnalyticsService $analytics,
        private readonly GrowthConfigService $config,
    ) {}

    public function index(Request $request): Response
    {
        $days = max(7, min(90, (int) $request->integer('days', 30)));
        $overview = $this->analytics->adminOverviewPayload($days);

        $recentLogs = GrowthActivityLog::query()
            ->with(['actor:id,username', 'targetUser:id,username'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (GrowthActivityLog $l): array => [
                'id' => $l->id,
                'action' => $l->action,
                'actor_username' => $l->actor?->username,
                'target_username' => $l->targetUser?->username,
                'metadata' => $l->metadata,
                'created_at' => $l->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Growth/Overview', [
            'days' => $days,
            'overview' => $overview,
            'settings' => $this->config->settingsPayload(),
            'recentLogs' => $recentLogs,
        ]);
    }

    public function refreshAnalytics(Request $request): RedirectResponse
    {
        $row = $this->analytics->snapshotDailyMetrics();

        return back()->with('status', __('Growth analytics refreshed for :date (Human users: :count).', [
            'date' => $row->date,
            'count' => $row->total_human_users,
        ]));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'referral_enabled' => ['required', 'boolean'],
            'referral_rewards_auto_grant' => ['required', 'boolean'],
            'referral_attribution_days' => ['required', 'integer', 'min:1', 'max:90'],
            'referral_verification_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'referral_require_email_verified' => ['required', 'boolean'],
            'referral_require_onboarding_or_activity' => ['required', 'boolean'],
            'referral_max_reward_days_per_user' => ['required', 'integer', 'min:1', 'max:1095'],
            'referral_visit_retention_days' => ['required', 'integer', 'min:7', 'max:365'],
            'early_member_enabled' => ['required', 'boolean'],
            'early_member_max_slots' => ['required', 'integer', 'min:1', 'max:100000'],
            'early_member_premium_days' => ['required', 'integer', 'min:0', 'max:365'],
            'early_member_badge_enabled' => ['required', 'boolean'],
            'founding_member_enabled' => ['required', 'boolean'],
            'founding_member_max_slots' => ['required', 'integer', 'min:1', 'max:50000'],
            'onboarding_enabled' => ['required', 'boolean'],
            'retention_reengagement_enabled' => ['required', 'boolean'],
            'retention_digest_enabled' => ['required', 'boolean'],
            'retention_reengagement_days' => ['required', 'string', 'max:40', 'regex:/^[0-9,\s]+$/'],
            'retention_frequency_cap_hours' => ['required', 'integer', 'min:24', 'max:720'],
            'fraud_velocity_hourly_threshold' => ['required', 'integer', 'min:2', 'max:100'],
            'fraud_manual_review_score' => ['required', 'integer', 'min:20', 'max:95'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $boolMap = [
            'referral_enabled' => 'referral',
            'referral_rewards_auto_grant' => 'referral',
            'referral_require_email_verified' => 'referral',
            'referral_require_onboarding_or_activity' => 'referral',
            'early_member_enabled' => 'early_member',
            'early_member_badge_enabled' => 'early_member',
            'founding_member_enabled' => 'founding_member',
            'onboarding_enabled' => 'onboarding',
            'retention_reengagement_enabled' => 'retention',
            'retention_digest_enabled' => 'retention',
        ];
        foreach ($boolMap as $k => $group) {
            $this->config->set($k, (bool) $validated[$k], $group, 'boolean', $actor);
        }

        $intMap = [
            'referral_attribution_days' => 'referral',
            'referral_verification_hours' => 'referral',
            'referral_max_reward_days_per_user' => 'referral',
            'referral_visit_retention_days' => 'privacy',
            'early_member_max_slots' => 'early_member',
            'early_member_premium_days' => 'early_member',
            'founding_member_max_slots' => 'founding_member',
            'retention_frequency_cap_hours' => 'retention',
            'fraud_velocity_hourly_threshold' => 'fraud',
            'fraud_manual_review_score' => 'fraud',
        ];
        foreach ($intMap as $k => $group) {
            $this->config->set($k, (int) $validated[$k], $group, 'integer', $actor);
        }

        $this->config->set('retention_reengagement_days', trim($validated['retention_reengagement_days']), 'retention', 'string', $actor);

        $this->config->logActivity('growth.settings.updated', $actor, null, null, null, [
            'referral_enabled' => (bool) $validated['referral_enabled'],
            'referral_rewards_auto_grant' => (bool) $validated['referral_rewards_auto_grant'],
            'early_member_enabled' => (bool) $validated['early_member_enabled'],
        ]);

        return back()->with('status', __('Growth program settings and guardrails updated.'));
    }
}
