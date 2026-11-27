<?php

namespace App\Services\Seo;

use App\Models\SeoOrganicEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrganicGrowthService
{
    public const SESSION_ATTRIBUTION_KEY = 'syncviet.seo.organic_attribution';

    /**
     * @var array<string, string>
     */
    private const SEARCH_ENGINES = [
        'google.' => 'google',
        'bing.com' => 'bing',
        'duckduckgo.com' => 'duckduckgo',
        'search.yahoo.' => 'yahoo',
        'yandex.' => 'yandex',
        'baidu.com' => 'baidu',
        'search.naver.com' => 'naver',
        'ecosia.org' => 'ecosia',
    ];

    public function detectSearchEngine(?string $referer): ?string
    {
        if (! is_string($referer) || trim($referer) === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $lower = strtolower($host);
        foreach (self::SEARCH_ENGINES as $needle => $engine) {
            if (str_contains($lower, $needle)) {
                return $engine;
            }
        }

        return null;
    }

    /**
     * Record a privacy-safe organic arrival without storing IP addresses or PII.
     */
    public function captureOrganicVisitIfApplicable(Request $request): void
    {
        if (! $request->isMethod('GET') || $request->expectsJson() || ! $request->hasSession()) {
            return;
        }

        if ($request->user() !== null && ! $request->user()->isHumanMember()) {
            return;
        }

        try {
            if (! Schema::hasTable('seo_organic_events')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $referer = $request->headers->get('referer');
        $engine = $this->detectSearchEngine($referer);
        $utmMedium = strtolower((string) $request->query('utm_medium', ''));
        $utmSource = strtolower((string) $request->query('utm_source', ''));

        if ($engine === null && $utmMedium !== 'organic') {
            return;
        }

        $landingPath = Str::limit('/'.ltrim($request->path(), '/'), 250, '');
        $locale = app()->getLocale();
        $sessionHash = hash('sha256', $request->session()->getId().'|'.now()->toDateString());

        $attribution = [
            'source' => $engine !== null ? 'organic_search' : ($utmSource !== '' ? Str::limit($utmSource, 32, '') : 'organic'),
            'search_engine' => $engine ?? ($utmSource !== '' ? Str::limit($utmSource, 32, '') : null),
            'landing_path' => $landingPath,
            'locale' => $locale,
        ];

        if (! $request->session()->has(self::SESSION_ATTRIBUTION_KEY)) {
            $request->session()->put(self::SESSION_ATTRIBUTION_KEY, $attribution);

            SeoOrganicEvent::query()->create([
                'event_type' => 'organic_visit',
                'session_hash' => $sessionHash,
                'user_id' => $request->user()?->id,
                'source' => $attribution['source'],
                'search_engine' => $attribution['search_engine'],
                'landing_path' => $landingPath,
                'locale' => $locale,
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordEvent(
        string $eventType,
        ?Request $request = null,
        ?User $user = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $metadata = [],
    ): void {
        if (! Schema::hasTable('seo_organic_events')) {
            return;
        }

        if ($user !== null && ! $user->isHumanMember()) {
            return;
        }

        $attribution = $request !== null && $request->hasSession()
            ? $request->session()->get(self::SESSION_ATTRIBUTION_KEY)
            : null;

        if (! is_array($attribution)) {
            if ($user !== null && is_string($user->acquisition_source) && $user->acquisition_source !== '') {
                $attribution = [
                    'source' => $user->acquisition_source,
                    'search_engine' => null,
                    'landing_path' => $user->acquisition_landing_url,
                    'locale' => $user->acquisition_locale ?: app()->getLocale(),
                ];
            } else {
                return;
            }
        }

        $sessionHash = $request !== null && $request->hasSession()
            ? hash('sha256', $request->session()->getId().'|'.now()->toDateString())
            : null;

        $requestUser = $request !== null ? $request->user() : null;
        $actorId = $user !== null
            ? $user->id
            : ($requestUser !== null ? $requestUser->id : null);

        SeoOrganicEvent::query()->create([
            'event_type' => $eventType,
            'session_hash' => $sessionHash,
            'user_id' => $actorId,
            'source' => (string) ($attribution['source'] ?? 'organic_search'),
            'search_engine' => is_string($attribution['search_engine'] ?? null) ? $attribution['search_engine'] : null,
            'landing_path' => is_string($attribution['landing_path'] ?? null) ? $attribution['landing_path'] : null,
            'locale' => is_string($attribution['locale'] ?? null) ? $attribution['locale'] : app()->getLocale(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata !== [] ? $metadata : null,
            'occurred_at' => now(),
        ]);
    }

    public function attributeNewRegistration(User $user, ?Request $request = null): void
    {
        $attribution = $request !== null && $request->hasSession()
            ? $request->session()->get(self::SESSION_ATTRIBUTION_KEY)
            : null;

        if (! is_array($attribution)) {
            return;
        }

        $user->forceFill([
            'acquisition_source' => Str::limit((string) ($attribution['source'] ?? 'organic_search'), 40, ''),
            'acquisition_landing_url' => is_string($attribution['landing_path'] ?? null) ? Str::limit($attribution['landing_path'], 250, '') : null,
            'acquisition_locale' => is_string($attribution['locale'] ?? null) ? Str::limit($attribution['locale'], 12, '') : app()->getLocale(),
        ])->saveQuietly();

        $this->recordEvent('registration', $request, $user);
    }

    /**
     * Privacy-safe Organic Growth Funnel summary for Admin SEO Dashboard.
     *
     * @return array<string, mixed>
     */
    public function funnelSummary(int $days = 30): array
    {
        $since = now()->subDays(max(1, min(365, $days)));

        $countsByEvent = SeoOrganicEvent::query()
            ->where('occurred_at', '>=', $since)
            ->select('event_type', DB::raw('COUNT(*) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();

        $visits = $countsByEvent['organic_visit'] ?? 0;
        $registrations = $countsByEvent['registration'] ?? 0;

        $byLocale = DB::table('seo_organic_events')
            ->where('occurred_at', '>=', $since)
            ->where('event_type', 'organic_visit')
            ->select('locale', DB::raw('COUNT(*) as visits'))
            ->groupBy('locale')
            ->orderByDesc('visits')
            ->limit(15)
            ->get()
            ->map(static fn (\stdClass $r): array => ['locale' => (string) ($r->locale ?: 'en'), 'visits' => (int) $r->visits])
            ->values()
            ->all();

        $topLanding = DB::table('seo_organic_events')
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('landing_path')
            ->select(
                'landing_path',
                DB::raw("SUM(CASE WHEN event_type = 'organic_visit' THEN 1 ELSE 0 END) as visits"),
                DB::raw("SUM(CASE WHEN event_type = 'registration' THEN 1 ELSE 0 END) as registrations"),
            )
            ->groupBy('landing_path')
            ->orderByDesc('visits')
            ->limit(10)
            ->get()
            ->map(static fn (\stdClass $r): array => [
                'landing_path' => (string) $r->landing_path,
                'visits' => (int) $r->visits,
                'registrations' => (int) $r->registrations,
            ])
            ->values()
            ->all();

        return [
            'window_days' => $days,
            'funnel' => [
                'organic_visit' => $visits,
                'post_view' => $countsByEvent['post_view'] ?? 0,
                'register_click' => $countsByEvent['register_click'] ?? 0,
                'registration' => $registrations,
                'first_interaction' => $countsByEvent['first_interaction'] ?? 0,
                'join_community' => $countsByEvent['join_community'] ?? 0,
                'premium_purchase' => $countsByEvent['premium_purchase'] ?? 0,
            ],
            'visit_to_signup_rate' => $visits > 0 ? round(($registrations / $visits) * 100, 2) : 0.0,
            'by_locale' => $byLocale,
            'top_landing_pages' => $topLanding,
        ];
    }
}
