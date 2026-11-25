<?php

namespace App\Http\Controllers\Admin\Monetization;

use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\BoostPackage;
use App\Services\Ads\AdCampaignService;
use App\Services\Monetization\BoostPostService;
use App\Services\Security\AuditLogger;
use Database\Seeders\MonetizationSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BoostAdminController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Response
    {
        MonetizationSeeder::seedDefaultsIfEmpty();

        $packages = BoostPackage::query()
            ->orderBy('sort_order')
            ->orderBy('duration_hours')
            ->get()
            ->map(static fn (BoostPackage $p): array => BoostPostService::serializePackage($p))
            ->values()
            ->all();

        $boostCampaigns = AdCampaign::query()
            ->where('billing_mode', 'fixed_package')
            ->with(['owner:id,name,username', 'post:id,title,content', 'boostPackage:id,name,duration_hours'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static function (AdCampaign $c): array {
                $row = AdCampaignService::serializeCampaign($c);
                $row['owner_name'] = $c->owner?->name;
                $row['owner_username'] = $c->owner?->username;
                $row['package_name'] = $c->boostPackage?->name;

                return $row;
            })
            ->values()
            ->all();

        return Inertia::render('admin/Monetization/BoostPackages', [
            'packages' => $packages,
            'boostCampaigns' => $boostCampaigns,
        ])->withViewData([
            'seo' => ['title' => __('Boost Packages Management').' - Admin', 'noindex' => true],
        ]);
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'max:64', 'unique:boost_packages,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'duration_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'price_usd_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'price_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'is_featured_slot' => ['required', 'boolean'],
            'priority_weight' => ['required', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $slug = ! empty($validated['slug'])
            ? Str::slug((string) $validated['slug'])
            : Str::slug((string) $validated['name']).'-'.Str::lower(Str::random(4));

        $pkg = BoostPackage::query()->create([
            ...$validated,
            'slug' => $slug,
            'benefits' => ['sponsored_feed_placement', 'live_impressions_clicks', 'approved_review'],
        ]);

        $this->auditLogger->log('admin.boost_package.created', $pkg, ['slug' => $pkg->slug], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boost package created.')]);

        return back();
    }

    public function updatePackage(Request $request, BoostPackage $boostPackage): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'duration_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'price_usd_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'price_vnd' => ['required', 'integer', 'min:0', 'max:500000000'],
            'is_featured_slot' => ['required', 'boolean'],
            'priority_weight' => ['required', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $boostPackage->update($validated);
        $this->auditLogger->log('admin.boost_package.updated', $boostPackage, ['slug' => $boostPackage->slug], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boost package updated.')]);

        return back();
    }
}
