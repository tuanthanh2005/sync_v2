<?php

namespace App\Http\Middleware;

use App\Services\System\LanguageService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    public const SUPPORTED_LOCALES = [
        'en', 'vi', 'es', 'pt-BR', 'id', 'hi', 'th', 'fil',
        'fr', 'de', 'ja', 'ko', 'zh-CN', 'ar', 'tr',
    ];

    public function __construct(
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Resolve and activate the interface locale with English as default and fallback.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $enabledCodes = $this->languageService->enabledCodes();

        if ($enabledCodes === []) {
            $enabledCodes = LanguageService::allCodes();
        }

        // Respect explicit URL locale prefix (e.g., /vi, /ja/topics/...) without IP-redirecting bots.
        $firstSegment = $request->segment(1);
        $candidate = is_string($firstSegment) && in_array($firstSegment, $enabledCodes, true)
            ? $firstSegment
            : null;

        // Also honor explicit valid shared URL query locale (?lang=xx or ?locale=xx) above session/browser defaults.
        if ($candidate === null) {
            $queryCandidate = $request->query('lang') ?? $request->query('locale');
            if (is_string($queryCandidate) && in_array($queryCandidate, $enabledCodes, true)) {
                $candidate = $queryCandidate;
            }
        }

        if ($candidate === null) {
            $candidate = $request->hasSession()
                ? $request->session()->get('locale')
                : null;
        }

        if (! is_string($candidate) || ! in_array($candidate, $enabledCodes, true)) {
            $cookieLocale = $request->cookie('syncviet_locale');
            $candidate = is_string($cookieLocale) ? $cookieLocale : null;
        }

        if (! is_string($candidate) || ! in_array($candidate, $enabledCodes, true)) {
            $userLocale = $request->user()?->preferred_locale;
            $candidate = is_string($userLocale) ? $userLocale : null;
        }

        if (! is_string($candidate) || ! in_array($candidate, $enabledCodes, true)) {
            $candidate = $this->languageService->detectFromAcceptLanguage(
                $request->header('Accept-Language'),
            );
        }

        $defaultLocale = $this->languageService->defaultCode();
        $locale = is_string($candidate) && in_array($candidate, $enabledCodes, true)
            ? $candidate
            : (in_array($defaultLocale, $enabledCodes, true) ? $defaultLocale : 'en');

        App::setLocale($locale);
        View::share('isRtl', $this->languageService->isRtl($locale));

        return $next($request);
    }
}
