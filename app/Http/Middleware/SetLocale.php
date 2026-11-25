<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    public const SUPPORTED_LOCALES = ['vi', 'en'];

    /**
     * Apply the user's preferred locale from session or cookie.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->hasSession()
            ? $request->session()->get('locale')
            : null;

        if (! is_string($candidate)) {
            $candidate = $request->cookie('syncviet_locale');
        }

        $locale = is_string($candidate) && in_array($candidate, self::SUPPORTED_LOCALES, true)
            ? $candidate
            : (string) config('app.locale', 'vi');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'vi';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
