<?php

namespace App\Http\Controllers\Monetization;

use App\Enums\ModerationStatus;
use App\Enums\PaymentServiceType;
use App\Enums\PolicyStatus;
use App\Enums\PostVisibility;
use App\Http\Controllers\Controller;
use App\Models\BannerCampaign;
use App\Models\PolicyPage;
use App\Models\Post;
use App\Services\Monetization\AdSenseConfigService;
use App\Services\Monetization\BannerAdvertisingService;
use App\Services\Monetization\BoostPostService;
use App\Services\Monetization\MonetizationPaymentService;
use App\Services\Monetization\PlatformCapabilityService;
use App\Services\Monetization\PremiumSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServicesCenterController extends Controller
{
    public function __construct(
        private readonly PremiumSubscriptionService $premiumService,
        private readonly BannerAdvertisingService $bannerService,
        private readonly BoostPostService $boostService,
        private readonly MonetizationPaymentService $paymentService,
        private readonly PlatformCapabilityService $platformService,
        private readonly AdSenseConfigService $adSenseService,
    ) {}

    public function index(Request $request): Response
    {
        $plans = $this->premiumService->publicPlans();
        $placements = $this->bannerService->publicPlacements();
        $boostPackages = $this->boostService->publicPackages();

        $appName = (string) config('app.name', 'SyncViet');
        $canonical = route('monetization.services');

        return Inertia::render('monetization/Services', [
            'plans' => $plans,
            'placements' => $placements,
            'boostPackages' => $boostPackages,
            'platformCapabilities' => $this->platformService->capabilitiesForRequest($request),
            'exchangeRate' => $this->paymentService->exchangeRateVndPerUsd(),
        ])->withViewData([
            'seo' => [
                'title' => __('Services Center').' - '.$appName,
                'description' => __('Explore SyncViet Premium verified membership, Banner Advertising placements, and Boost Post packages.'),
                'url' => $canonical,
                'hreflangs' => $this->buildHreflangs($canonical),
                'jsonLd' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebPage',
                    'name' => $appName.' Services Center',
                    'url' => $canonical,
                ],
            ],
        ]);
    }

    public function premium(Request $request): Response
    {
        $user = $request->user();
        $platform = $this->platformService->resolvePlatform($request);
        $plans = $this->premiumService->publicPlans();
        $appName = (string) config('app.name', 'SyncViet');
        $canonical = route('monetization.premium');

        return Inertia::render('monetization/Premium', [
            'plans' => $plans,
            'paymentMethods' => $this->paymentService->availablePaymentMethods(null, $platform),
            'platformCapabilities' => $this->platformService->capabilitiesForRequest($request),
            'isPremium' => $user !== null && $user->isPremium(),
            'premiumUntil' => $user?->premium_until?->toIso8601String(),
            'pendingOrdersCount' => $user !== null ? $this->paymentService->openPendingCount($user, PaymentServiceType::Premium) : 0,
            'maxPendingOrders' => $this->paymentService->maxPendingPaymentsPerUser(),
        ])->withViewData([
            'seo' => [
                'title' => 'SyncViet Premium - '.$appName,
                'description' => __('Upgrade to SyncViet Premium for a blue member badge, reduced ads, and post analytics.'),
                'url' => $canonical,
                'hreflangs' => $this->buildHreflangs($canonical),
                'jsonLd' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => 'SyncViet Premium',
                    'description' => 'Time-limited SyncViet Premium membership with blue member badge and creator tools.',
                    'brand' => ['@type' => 'Brand', 'name' => $appName],
                ],
            ],
        ]);
    }

    public function advertising(Request $request): Response
    {
        $appName = (string) config('app.name', 'SyncViet');
        $canonical = route('monetization.advertising');

        return Inertia::render('monetization/Advertising', [
            'placements' => $this->bannerService->publicPlacements(),
            'boostPackages' => $this->boostService->publicPackages(),
            'platformCapabilities' => $this->platformService->capabilitiesForRequest($request),
        ])->withViewData([
            'seo' => [
                'title' => __('Advertising Center').' - '.$appName,
                'description' => __('Promote your brand on SyncViet with transparent Banner Advertising and fixed-duration Boost Post packages.'),
                'url' => $canonical,
                'hreflangs' => $this->buildHreflangs($canonical),
            ],
        ]);
    }

    public function banner(Request $request): Response
    {
        $user = $request->user();
        $platform = $this->platformService->resolvePlatform($request);
        $appName = (string) config('app.name', 'SyncViet');
        $canonical = route('monetization.banner');

        $myCampaigns = $user === null
            ? []
            : BannerCampaign::query()
                ->where('user_id', $user->id)
                ->with('placement:id,code,name')
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(static fn (BannerCampaign $c): array => BannerAdvertisingService::serializeCampaign($c))
                ->values()
                ->all();

        return Inertia::render('monetization/BannerAds', [
            'placements' => $this->bannerService->publicPlacements(),
            'myCampaigns' => $myCampaigns,
            'paymentMethods' => $this->paymentService->availablePaymentMethods(null, $platform),
            'platformCapabilities' => $this->platformService->capabilitiesForRequest($request),
            'pendingOrdersCount' => $user !== null ? $this->paymentService->openPendingCount($user, PaymentServiceType::Banner) : 0,
            'maxPendingOrders' => $this->paymentService->maxPendingPaymentsPerUser(),
        ])->withViewData([
            'seo' => [
                'title' => __('Banner Advertising').' - '.$appName,
                'description' => __('Rent exclusive or rotating desktop and mobile banner placements on SyncViet with transparent daily pricing.'),
                'url' => $canonical,
                'hreflangs' => $this->buildHreflangs($canonical),
            ],
        ]);
    }

    public function boost(Request $request): Response
    {
        $user = $request->user();
        $platform = $this->platformService->resolvePlatform($request);
        $appName = (string) config('app.name', 'SyncViet');
        $canonical = route('monetization.boost');

        $eligiblePosts = $user === null
            ? []
            : Post::query()
                ->where('user_id', $user->id)
                ->where('visibility', PostVisibility::Public->value)
                ->where('moderation_status', ModerationStatus::Approved->value)
                ->whereNotNull('published_at')
                ->latest('published_at')
                ->limit(25)
                ->get(['id', 'title', 'content', 'reactions_count', 'comments_count', 'published_at'])
                ->map(static fn (Post $p): array => [
                    'id' => $p->id,
                    'title' => $p->title ?: mb_substr($p->content, 0, 80),
                    'excerpt' => mb_substr($p->content, 0, 140),
                    'reactions_count' => $p->reactions_count,
                    'comments_count' => $p->comments_count,
                    'published_at' => $p->published_at?->toIso8601String(),
                ])
                ->values()
                ->all();

        return Inertia::render('monetization/BoostPost', [
            'packages' => $this->boostService->publicPackages(),
            'eligiblePosts' => $eligiblePosts,
            'paymentMethods' => $this->paymentService->availablePaymentMethods(null, $platform),
            'platformCapabilities' => $this->platformService->capabilitiesForRequest($request),
            'pendingOrdersCount' => $user !== null ? $this->paymentService->openPendingCount($user, PaymentServiceType::Boost) : 0,
            'maxPendingOrders' => $this->paymentService->maxPendingPaymentsPerUser(),
        ])->withViewData([
            'seo' => [
                'title' => __('Boost Post').' - '.$appName,
                'description' => __('Increase visibility for your public SyncViet posts with transparent fixed-duration Boost packages.'),
                'url' => $canonical,
                'hreflangs' => $this->buildHreflangs($canonical),
            ],
        ]);
    }

    public function bannerClick(Request $request, BannerCampaign $bannerCampaign): RedirectResponse
    {
        $destination = $this->bannerService->recordClickAndResolveDestination($bannerCampaign, $request->user(), $request);

        return redirect()->away($destination);
    }

    public function bannerEvent(Request $request, BannerCampaign $bannerCampaign): RedirectResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:hide,report'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $this->bannerService->recordViewerFeedback(
            $bannerCampaign,
            $request->user(),
            $request,
            (string) $validated['event_type'],
            isset($validated['reason']) ? (string) $validated['reason'] : null,
        );

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => $validated['event_type'] === 'hide'
                ? __('Banner hidden from your view.')
                : __('Thank you. This banner has been reported to our moderation team.'),
        ]);

        return back();
    }

    public function adsTxt(): HttpResponse
    {
        $content = $this->adSenseService->adsTxtContent();
        if ($content === null) {
            abort(404);
        }

        return response($content, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function sitemap(): HttpResponse
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0', 'changefreq' => 'hourly'],
            ['loc' => route('monetization.services'), 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => route('monetization.premium'), 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => route('monetization.advertising'), 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => route('monetization.banner'), 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => route('monetization.boost'), 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => route('communities.index'), 'priority' => '0.8', 'changefreq' => 'daily'],
        ];

        PolicyPage::query()
            ->where('status', PolicyStatus::Published->value)
            ->where('is_visible', true)
            ->get(['slug', 'updated_at'])
            ->each(static function (PolicyPage $p) use (&$urls): void {
                $urls[] = [
                    'loc' => route('policies.show', $p->slug),
                    'lastmod' => $p->updated_at?->toAtomString(),
                    'priority' => '0.5',
                    'changefreq' => 'monthly',
                ];
            });

        Post::query()
            ->where('visibility', PostVisibility::Public->value)
            ->where('moderation_status', ModerationStatus::Approved->value)
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->limit(200)
            ->get(['id', 'updated_at'])
            ->each(static function (Post $post) use (&$urls): void {
                $urls[] = [
                    'loc' => route('posts.show', $post->id),
                    'lastmod' => $post->updated_at?->toAtomString(),
                    'priority' => '0.7',
                    'changefreq' => 'daily',
                ];
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $u) {
            $xml .= '  <url>';
            $xml .= '<loc>'.htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
            if (! empty($u['lastmod'])) {
                $xml .= '<lastmod>'.htmlspecialchars($u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</lastmod>';
            }
            $xml .= '<changefreq>'.$u['changefreq'].'</changefreq>';
            $xml .= '<priority>'.$u['priority'].'</priority>';
            $xml .= "</url>\n";
        }

        $xml .= "</urlset>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * @return array<string, string>
     */
    private function buildHreflangs(string $canonical): array
    {
        return [
            'x-default' => $canonical,
            'en' => $canonical,
            'vi' => $canonical,
        ];
    }
}
