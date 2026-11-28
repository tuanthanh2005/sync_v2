<?php

namespace App\Http\Controllers\Admin\Growth;

use App\Enums\ReferralStatus;
use App\Http\Controllers\Controller;
use App\Models\GrowthCampaign;
use App\Models\GrowthFraudReview;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\ReferralReward;
use App\Models\ReferralRewardRule;
use App\Models\User;
use App\Services\Growth\GrowthConfigService;
use App\Services\Growth\ReferralService;
use App\Services\Growth\RewardEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GrowthReferralsAndFraudController extends Controller
{
    public function __construct(
        private readonly ReferralService $referralService,
        private readonly RewardEngineService $rewardEngine,
        private readonly GrowthConfigService $config,
    ) {}

    public function index(Request $request): Response
    {
        $statusFilter = trim($request->string('status')->toString());

        $referralsQuery = Referral::query()
            ->with(['referrer:id,name,username', 'referee:id,name,username,email_verified_at', 'referralCode:id,code'])
            ->latest('id');

        if ($statusFilter !== '' && ReferralStatus::tryFrom($statusFilter) !== null) {
            $referralsQuery->where('status', $statusFilter);
        }

        $referrals = $referralsQuery->limit(60)->get()->map(static fn (Referral $r): array => [
            'id' => $r->id,
            'code' => $r->referralCode !== null ? $r->referralCode->code : '—',
            'referrer_username' => $r->referrer?->username,
            'referee_username' => $r->referee?->username,
            'referee_verified' => $r->referee !== null && $r->referee->hasVerifiedEmail(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'status_reason' => $r->status_reason,
            'risk_score' => $r->risk_score,
            'risk_signals' => $r->risk_signals ?? [],
            'eligible_at' => $r->eligible_at?->toIso8601String(),
            'qualified_at' => $r->qualified_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ])->values()->all();

        $fraudReviews = GrowthFraudReview::query()
            ->with(['user:id,username', 'referral:id,status,referee_id', 'reviewer:id,username'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static fn (GrowthFraudReview $f): array => [
                'id' => $f->id,
                'referral_id' => $f->referral_id,
                'username' => $f->user?->username,
                'risk_score' => $f->risk_score,
                'signals' => $f->signals ?? [],
                'status' => $f->status,
                'decision_note' => $f->decision_note,
                'appeal_note' => $f->appeal_note,
                'reviewer_username' => $f->reviewer?->username,
                'created_at' => $f->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $rules = ReferralRewardRule::query()
            ->with('campaign:id,name')
            ->orderBy('required_qualified_referrals')
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (ReferralRewardRule $rule): array => [
                'id' => $rule->id,
                'slug' => $rule->slug,
                'name' => $rule->name,
                'campaign_id' => $rule->campaign_id,
                'campaign_name' => $rule->campaign?->name,
                'required_qualified_referrals' => $rule->required_qualified_referrals,
                'cumulative_premium_days' => $rule->cumulative_premium_days,
                'is_repeatable' => $rule->is_repeatable,
                'max_claims_per_user' => $rule->max_claims_per_user,
                'is_active' => $rule->is_active,
            ])
            ->values()
            ->all();

        $rewards = ReferralReward::query()
            ->with(['user:id,username', 'rule:id,name'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (ReferralReward $rw): array => [
                'id' => $rw->id,
                'user_id' => $rw->user_id,
                'username' => $rw->user?->username,
                'rule_name' => $rw->rule?->name,
                'milestone_referrals' => $rw->milestone_referrals,
                'days_granted' => $rw->days_granted,
                'cumulative_days_target' => $rw->cumulative_days_target,
                'source' => $rw->source,
                'status' => $rw->status,
                'note' => $rw->note,
                'revoke_reason' => $rw->revoke_reason,
                'granted_at' => $rw->granted_at->toIso8601String(),
            ])
            ->values()
            ->all();

        $codes = ReferralCode::query()
            ->with('user:id,username')
            ->orderByDesc('qualified_count')
            ->orderByDesc('registrations_count')
            ->limit(30)
            ->get()
            ->map(static fn (ReferralCode $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'username' => $c->user?->username,
                'is_active' => $c->is_active,
                'disabled_reason' => $c->disabled_reason,
                'visits_count' => $c->visits_count,
                'registrations_count' => $c->registrations_count,
                'qualified_count' => $c->qualified_count,
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Growth/ReferralsAndFraud', [
            'statusFilter' => $statusFilter,
            'referrals' => $referrals,
            'fraudReviews' => $fraudReviews,
            'rules' => $rules,
            'rewards' => $rewards,
            'codes' => $codes,
            'campaigns' => GrowthCampaign::query()->orderBy('id')->get(['id', 'name', 'type', 'status']),
            'statuses' => array_map(static fn (ReferralStatus $s): array => [
                'value' => $s->value,
                'label' => $s->label(),
            ], ReferralStatus::cases()),
        ]);
    }

    public function runQualificationNow(): RedirectResponse
    {
        $res = $this->referralService->processPendingQualifications(100);

        return back()->with('status', __('Checked :checked pending referrals: :qualified qualified, :review flagged for manual review, :rewards rewards granted.', [
            'checked' => $res['checked'],
            'qualified' => $res['qualified'],
            'review' => $res['under_review'],
            'rewards' => $res['rewards_granted'],
        ]));
    }

    public function reviewReferral(Request $request, Referral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'revoke'])],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->referralService->adminReviewReferral($referral, $validated['decision'], $validated['reason'], $actor);

        return back()->with('status', __('Referral decision recorded and logged.'));
    }

    public function toggleCode(Request $request, ReferralCode $referralCode): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $nextActive = ! $referralCode->is_active;

        $referralCode->forceFill([
            'is_active' => $nextActive,
            'disabled_reason' => $nextActive ? null : ($validated['reason'] ?? 'Disabled by administrator'),
            'disabled_by' => $nextActive ? null : $actor->id,
            'disabled_at' => $nextActive ? null : now(),
        ])->save();

        $this->config->logActivity('growth.referral_code.toggled', $actor, $referralCode->user_id, ReferralCode::class, $referralCode->id, [
            'is_active' => $nextActive,
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('status', __('Referral code status updated.'));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'campaign_id' => ['nullable', 'integer', 'exists:growth_campaigns,id'],
            'required_qualified_referrals' => ['required', 'integer', 'min:1', 'max:10000'],
            'cumulative_premium_days' => ['required', 'integer', 'min:1', 'max:1095'],
            'is_repeatable' => ['sometimes', 'boolean'],
            'max_claims_per_user' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $rule = ReferralRewardRule::query()->create([
            'campaign_id' => $validated['campaign_id'] ?? null,
            'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(4)),
            'name' => trim($validated['name']),
            'required_qualified_referrals' => (int) $validated['required_qualified_referrals'],
            'cumulative_premium_days' => (int) $validated['cumulative_premium_days'],
            'is_repeatable' => (bool) ($validated['is_repeatable'] ?? false),
            'max_claims_per_user' => (int) ($validated['max_claims_per_user'] ?? 1),
            'is_active' => true,
            'sort_order' => (int) $validated['required_qualified_referrals'],
        ]);

        $this->config->logActivity('growth.rule.created', $actor, null, ReferralRewardRule::class, $rule->id, [
            'required' => $rule->required_qualified_referrals,
            'days' => $rule->cumulative_premium_days,
        ]);

        return back()->with('status', __('Referral milestone rule created.'));
    }

    public function updateRule(Request $request, ReferralRewardRule $rule): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'required_qualified_referrals' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'cumulative_premium_days' => ['sometimes', 'integer', 'min:1', 'max:1095'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $rule->forceFill($validated)->save();

        $this->config->logActivity('growth.rule.updated', $actor, null, ReferralRewardRule::class, $rule->id, $validated);

        return back()->with('status', __('Referral milestone rule updated.'));
    }

    public function manualGrant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'exists:users,username'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'source' => ['required', Rule::in(['referral_reward', 'early_member_reward', 'founding_member', 'promotional_campaign', 'admin_grant'])],
            'reason' => ['required', 'string', 'min:5', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->where('username', $validated['username'])->firstOrFail();

        $this->rewardEngine->manualGrantReward($target, (int) $validated['days'], $validated['source'], $validated['reason'], $actor);

        return back()->with('status', __('Promotional Premium reward (+:days days) granted to @:user.', [
            'days' => $validated['days'],
            'user' => $target->username,
        ]));
    }

    public function revokeReward(Request $request, ReferralReward $reward): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->rewardEngine->revokeReward($reward, $validated['reason'], $actor);

        return back()->with('status', __('Promotional reward revoked safely without affecting legally purchased Premium time.'));
    }

    public function exportReferralsCsv(): StreamedResponse
    {
        $rows = Referral::query()
            ->with(['referrer:id,username', 'referee:id,username', 'referralCode:id,code'])
            ->latest('id')
            ->limit(1000)
            ->get();

        $safeCell = static function (string $value): string {
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'".$value;
            }

            return $value;
        };

        return response()->streamDownload(static function () use ($rows, $safeCell): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['ID', 'Code', 'Referrer', 'Referee', 'Status', 'RiskScore', 'Reason', 'QualifiedAt', 'CreatedAt']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->id,
                    $safeCell($r->referralCode !== null ? $r->referralCode->code : ''),
                    $safeCell($r->referrer !== null ? $r->referrer->username : ''),
                    $safeCell($r->referee !== null ? $r->referee->username : ''),
                    $r->status->value,
                    $r->risk_score,
                    $safeCell((string) ($r->status_reason ?? '')),
                    $r->qualified_at?->toIso8601String() ?? '',
                    $r->created_at?->toIso8601String() ?? '',
                ]);
            }
            fclose($out);
        }, 'sysnoxis-referrals-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
