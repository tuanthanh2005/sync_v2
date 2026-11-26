<?php

namespace App\Http\Controllers\Admin\Seo;

use App\Enums\SeoVerificationMethod;
use App\Http\Controllers\Controller;
use App\Models\SeoApiConnection;
use App\Models\SeoApiUsage;
use App\Models\SeoIndexNowSubmission;
use App\Services\Seo\CloudflareAndPageSpeedService;
use App\Services\Seo\GoogleSearchConsoleService;
use App\Services\Seo\GoogleSiteVerificationService;
use App\Services\Seo\IndexNowService;
use App\Services\Seo\KeywordIntelligenceService;
use App\Services\Seo\SeoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SeoIntegrationsController extends Controller
{
    public function __construct(
        private readonly SeoConfigService $config,
        private readonly GoogleSiteVerificationService $googleVerification,
        private readonly GoogleSearchConsoleService $gsc,
        private readonly IndexNowService $indexNow,
        private readonly CloudflareAndPageSpeedService $cloudflarePageSpeed,
        private readonly KeywordIntelligenceService $keywordIntelligence,
    ) {}

    public function index(): Response
    {
        $verifyConn = $this->googleVerification->connection();
        $cfConn = SeoApiConnection::query()->where('provider', 'cloudflare')->first();

        $recentIndexNow = SeoIndexNowSubmission::query()
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(static fn (SeoIndexNowSubmission $s): array => [
                'id' => $s->id,
                'url' => $s->url,
                'status' => $s->status,
                'http_status' => $s->http_status,
                'attempts' => $s->attempts,
                'response_excerpt' => $s->response_excerpt,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $apiUsage = SeoApiUsage::query()
            ->where('usage_date', '>=', now()->subDays(14)->toDateString())
            ->orderByDesc('usage_date')
            ->limit(40)
            ->get()
            ->map(static fn (SeoApiUsage $u): array => [
                'provider' => $u->provider,
                'endpoint' => $u->endpoint,
                'usage_date' => $u->usage_date->toDateString(),
                'requests_count' => $u->requests_count,
                'errors_count' => $u->errors_count,
                'quota_limit' => $u->quota_limit,
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Seo/Integrations', [
            'canonicalOrigin' => $this->config->canonicalBaseUrl(),
            'canonicalHost' => $this->config->canonicalHost(),
            'oauthCallbackUrl' => route('admin.seo.integrations.gsc.oauth-callback'),
            'verification' => [
                'property_identifier' => $verifyConn->property_identifier ?: 'sc-domain:'.$this->config->canonicalHost(),
                'method' => $verifyConn->verification_method ?: SeoVerificationMethod::DnsTxt->value,
                'token' => $verifyConn->verification_token ?: '',
                'status' => $verifyConn->status,
                'verified_at' => $verifyConn->verified_at?->toIso8601String(),
                'last_error' => $verifyConn->last_error,
                'bing_meta_verification' => $this->config->string('bing_meta_verification', ''),
            ],
            'gsc' => $this->gsc->safeSummary(),
            'indexNow' => [
                'enabled' => $this->config->bool('indexnow_enabled', false),
                'key' => $this->indexNow->getKey(),
                'key_url' => $this->indexNow->getKey() !== '' ? $this->config->canonicalBaseUrl().'/'.$this->indexNow->getKey().'.txt' : null,
                'queued_count' => SeoIndexNowSubmission::query()->where('status', 'queued')->count(),
                'recent' => $recentIndexNow,
            ],
            'ga4' => [
                'enabled' => $this->config->bool('ga4_enabled', false),
                'measurement_id' => $this->config->string('ga4_measurement_id', ''),
                'require_consent' => $this->config->bool('ga4_require_consent', true),
            ],
            'cloudflare' => [
                'enabled' => $this->config->bool('cloudflare_purge_enabled', false),
                'zone_id' => $this->config->string('cloudflare_zone_id', ''),
                'has_token' => $cfConn !== null && $cfConn->hasCredentials(),
                'status' => $cfConn !== null ? $cfConn->status : 'not_configured',
                'last_synced_at' => $cfConn?->last_synced_at?->toIso8601String(),
                'last_error' => $cfConn?->last_error,
            ],
            'pageSpeedConfigured' => trim((string) (config('syncviet.seo.pagespeed_api_key') ?: $this->config->string('pagespeed_api_key', ''))) !== '',
            'apiUsage' => $apiUsage,
        ]);
    }

    public function saveVerification(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'method' => ['required', Rule::in(array_column(SeoVerificationMethod::cases(), 'value'))],
            'verification_token' => ['required', 'string', 'max:220'],
            'property_identifier' => ['nullable', 'string', 'max:200'],
            'bing_meta_verification' => ['nullable', 'string', 'max:120'],
        ]);

        $this->googleVerification->configure(
            SeoVerificationMethod::from((string) $validated['method']),
            (string) $validated['verification_token'],
            is_string($validated['property_identifier'] ?? null) ? $validated['property_identifier'] : null,
            $user,
        );

        if (array_key_exists('bing_meta_verification', $validated)) {
            $this->config->set('bing_meta_verification', trim((string) ($validated['bing_meta_verification'] ?? '')), 'verification', 'string', false, $user);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Verification settings saved with status Pending Verification. Run Verify Now after DNS/HTML propagation.'),
        ]);

        return back();
    }

    public function checkVerification(Request $request): RedirectResponse
    {
        $result = $this->googleVerification->verifyNow($request->user());

        Inertia::flash('toast', [
            'type' => $result['verified'] ? 'success' : 'info',
            'message' => $result['message'],
        ]);

        return back();
    }

    public function saveGscServiceAccount(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'property_identifier' => ['required', 'string', 'max:200'],
            'service_account_json' => ['required', 'string', 'max:20000'],
        ]);

        $this->gsc->saveServiceAccount(
            (string) $validated['service_account_json'],
            (string) $validated['property_identifier'],
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Google Service Account saved securely (encrypted). Click Test Connection to verify API access.'),
        ]);

        return back();
    }

    public function saveGscOAuthClient(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'property_identifier' => ['required', 'string', 'max:200'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);

        $this->gsc->saveOAuthClient(
            (string) $validated['client_id'],
            (string) $validated['client_secret'],
            (string) $validated['property_identifier'],
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Google OAuth client saved (encrypted). Proceed to Authorize with Google.'),
        ]);

        return back();
    }

    public function redirectGscOAuth(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('syncviet.seo.gsc_oauth_state', $state);

        $url = $this->gsc->buildOAuthAuthorizeUrl(route('admin.seo.integrations.gsc.oauth-callback'), $state);
        if ($url === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Configure OAuth Client ID and Secret first.')]);

            return back();
        }

        return redirect()->away($url);
    }

    public function callbackGscOAuth(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $expectedState = (string) $request->session()->pull('syncviet.seo.gsc_oauth_state', '');
        $incomingState = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($expectedState === '' || ! hash_equals($expectedState, $incomingState) || $code === '') {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invalid or expired OAuth state parameter.')]);

            return to_route('admin.seo.integrations');
        }

        $ok = $this->gsc->exchangeOAuthCode($code, route('admin.seo.integrations.gsc.oauth-callback'), $user);

        Inertia::flash('toast', [
            'type' => $ok ? 'success' : 'error',
            'message' => $ok ? __('Google Search Console connected via OAuth 2.0.') : __('Google OAuth token exchange failed.'),
        ]);

        return to_route('admin.seo.integrations');
    }

    public function testGscConnection(): RedirectResponse
    {
        $res = $this->gsc->testConnection();

        Inertia::flash('toast', [
            'type' => $res['ok'] ? 'success' : 'error',
            'message' => $res['message'],
        ]);

        return back();
    }

    public function disconnectGsc(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->gsc->disconnect($user);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Google Search Console disconnected and credentials removed.')]);

        return back();
    }

    public function syncGscAnalytics(): RedirectResponse
    {
        $res = $this->gsc->syncSearchAnalytics(28);
        if ($res['ok']) {
            $this->keywordIntelligence->refreshRecommendations(28);
        }

        Inertia::flash('toast', [
            'type' => $res['ok'] ? 'success' : 'error',
            'message' => $res['message'].' ('.$res['rows_synced'].' rows)',
        ]);

        return back();
    }

    public function submitGscSitemap(): RedirectResponse
    {
        $res = $this->gsc->submitSitemapToGsc();

        Inertia::flash('toast', [
            'type' => $res['ok'] ? 'success' : 'error',
            'message' => $res['message'],
        ]);

        return back();
    }

    public function toggleIndexNow(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->indexNow->setEnabled((bool) $validated['enabled'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('IndexNow configuration updated.')]);

        return back();
    }

    public function regenerateIndexNowKey(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->indexNow->regenerateKey($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New IndexNow verification key generated.')]);

        return back();
    }

    public function submitIndexNowBatch(): RedirectResponse
    {
        $res = $this->indexNow->submitQueuedBatch(100);

        Inertia::flash('toast', [
            'type' => $res['failed'] === 0 ? 'success' : 'warning',
            'message' => $res['message'],
        ]);

        return back();
    }

    public function saveGa4(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'measurement_id' => ['nullable', 'string', 'max:32', 'regex:/^(|G-[A-Z0-9]{6,16})$/'],
            'require_consent' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['enabled'] && trim((string) ($validated['measurement_id'] ?? '')) !== '';
        $this->config->set('ga4_enabled', $enabled, 'analytics', 'boolean', false, $user);
        $this->config->set('ga4_measurement_id', trim((string) ($validated['measurement_id'] ?? '')), 'analytics', 'string', false, $user);
        $this->config->set('ga4_require_consent', (bool) $validated['require_consent'], 'analytics', 'boolean', false, $user);

        SeoApiConnection::query()->updateOrCreate(
            ['provider' => 'ga4'],
            [
                'auth_type' => 'measurement_id',
                'property_identifier' => trim((string) ($validated['measurement_id'] ?? '')) ?: null,
                'status' => $enabled ? 'configured' : 'disabled',
                'updated_by' => $user->id,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Google Analytics 4 settings updated (zero raw JavaScript allowed).')]);

        return back();
    }

    public function saveCloudflare(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'zone_id' => ['nullable', 'string', 'max:80'],
            'api_token' => ['nullable', 'string', 'max:255'],
        ]);

        $this->cloudflarePageSpeed->configureCloudflare(
            (bool) $validated['enabled'],
            (string) ($validated['zone_id'] ?? ''),
            is_string($validated['api_token'] ?? null) ? $validated['api_token'] : null,
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cloudflare Cache Purge settings updated.')]);

        return back();
    }

    public function purgeCloudflare(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'urls' => ['nullable', 'string', 'max:2000'],
        ]);

        $raw = trim((string) ($validated['urls'] ?? ''));
        $urls = $raw !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])))
            : [$this->config->canonicalBaseUrl().'/sitemap.xml', $this->config->canonicalBaseUrl().'/robots.txt'];

        $res = $this->cloudflarePageSpeed->purgeUrls($urls);

        Inertia::flash('toast', [
            'type' => $res['ok'] ? 'success' : 'error',
            'message' => $res['message'],
        ]);

        return back();
    }

    public function runPageSpeed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'strategy' => ['nullable', Rule::in(['mobile', 'desktop'])],
        ]);

        return response()->json($this->cloudflarePageSpeed->inspectPageSpeed(
            (string) $validated['url'],
            (string) ($validated['strategy'] ?? 'mobile'),
        ));
    }
}
