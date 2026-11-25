<?php

namespace App\Http\Controllers\Admin\Monetization;

use App\Http\Controllers\Controller;
use App\Models\PremiumPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Monetization\PremiumSubscriptionService;
use App\Services\Security\AuditLogger;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PremiumAdminController extends Controller
{
    public function __construct(
        private readonly PremiumSubscriptionService $premiumService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        MonetizationSeeder::seedDefaultsIfEmpty();

        $search = trim($request->string('search')->toString());
        $status = $request->string('status', 'all')->toString();

        $plans = PremiumPlan::query()
            ->orderBy('sort_order')
            ->orderBy('duration_days')
            ->get()
            ->map(static fn (PremiumPlan $p): array => PremiumSubscriptionService::serializePlan($p))
            ->values()
            ->all();

        $subsQuery = Subscription::query()
            ->with(['user:id,name,username,email,premium_until,premium_suspended_at', 'plan:id,slug,name', 'granter:id,username']);

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $subsQuery->whereHas('user', static function (Builder $q) use ($pattern): void {
                $q->where('username', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('name', 'like', $pattern);
            });
        }

        if ($status !== 'all') {
            $subsQuery->where('status', $status);
        }

        $paginator = $subsQuery->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('admin/Monetization/Premium', [
            'plans' => $plans,
            'subscriptions' => [
                'data' => array_values(collect($paginator->items())->map(static fn (Subscription $s): array => [
                    'id' => $s->id,
                    'user_id' => $s->user_id,
                    'user_name' => $s->user !== null ? $s->user->name : 'User',
                    'username' => $s->user !== null ? $s->user->username : '-',
                    'email' => $s->user !== null ? $s->user->email : '-',
                    'plan_name' => $s->plan !== null ? $s->plan->name : '-',
                    'status' => $s->status->value,
                    'status_label' => $s->status->label(),
                    'source' => $s->source,
                    'currency' => $s->currency,
                    'amount_paid' => $s->amount_paid,
                    'starts_at' => $s->starts_at->toIso8601String(),
                    'expires_at' => $s->expires_at->toIso8601String(),
                    'granted_by_username' => $s->granter?->username,
                    'reason' => $s->reason,
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => ['search' => $search, 'status' => $status],
        ])->withViewData([
            'seo' => ['title' => __('Premium Management').' - Admin', 'noindex' => true],
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'max:64', 'unique:premium_plans,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:1095'],
            'price_usd_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'price_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'promo_price_usd_cents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'promo_price_vnd' => ['nullable', 'integer', 'min:0', 'max:500000000'],
            'ad_reduction_mode' => ['required', 'string', Rule::in(['none', 'reduced', 'ad_free'])],
            'benefits' => ['nullable', 'array'],
            'benefits.*' => ['string', 'max:80'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
        ]);

        $slug = ! empty($validated['slug'])
            ? Str::slug((string) $validated['slug'])
            : Str::slug((string) $validated['name']).'-'.Str::lower(Str::random(4));

        $plan = PremiumPlan::query()->create([
            ...$validated,
            'slug' => $slug,
        ]);

        $this->auditLogger->log('admin.premium_plan.created', $plan, ['slug' => $plan->slug], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Premium plan created.')]);

        return back();
    }

    public function updatePlan(Request $request, PremiumPlan $premiumPlan): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:1095'],
            'price_usd_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'price_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'promo_price_usd_cents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'promo_price_vnd' => ['nullable', 'integer', 'min:0', 'max:500000000'],
            'ad_reduction_mode' => ['required', 'string', Rule::in(['none', 'reduced', 'ad_free'])],
            'benefits' => ['nullable', 'array'],
            'benefits.*' => ['string', 'max:80'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
        ]);

        $premiumPlan->update($validated);
        $this->auditLogger->log('admin.premium_plan.updated', $premiumPlan, ['slug' => $premiumPlan->slug], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Premium plan updated.')]);

        return back();
    }

    public function grantSubscription(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'premium_plan_id' => ['required', 'integer', 'exists:premium_plans,id'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:1095'],
            'source' => ['required', 'string', Rule::in(['admin_grant', 'promotion'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $cleanUsername = ltrim(Str::lower(trim((string) $validated['username'])), '@');
        /** @var User|null $target */
        $target = User::query()
            ->where('username', $cleanUsername)
            ->orWhere('email', trim((string) $validated['username']))
            ->first();

        if ($target === null) {
            return back()->withErrors(['username' => __('Target user not found.')]);
        }

        /** @var PremiumPlan $plan */
        $plan = PremiumPlan::query()->findOrFail((int) $validated['premium_plan_id']);

        $this->premiumService->adminGrantPremium(
            actor: $actor,
            target: $target,
            plan: $plan,
            durationDays: (int) $validated['duration_days'],
            source: (string) $validated['source'],
            reason: (string) $validated['reason'],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Granted Premium (:days days) to @:username.', ['days' => $validated['duration_days'], 'username' => $target->username]),
        ]);

        return back();
    }

    public function manageSubscription(Request $request, Subscription $subscription): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['extend', 'suspend', 'restore', 'revoke'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'extra_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $this->premiumService->adminManageSubscription(
            actor: $actor,
            subscription: $subscription,
            action: (string) $validated['action'],
            reason: (string) $validated['reason'],
            extraDays: isset($validated['extra_days']) ? (int) $validated['extra_days'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription updated.')]);

        return back();
    }
}
