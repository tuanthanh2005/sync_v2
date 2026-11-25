<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocaleUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;

class LocaleController extends Controller
{
    /**
     * Persist and activate the selected interface language (vi or en).
     */
    public function __invoke(LocaleUpdateRequest $request): RedirectResponse
    {
        /** @var string $locale */
        $locale = $request->validated('locale');

        App::setLocale($locale);
        $request->session()->put('locale', $locale);

        Cookie::queue(cookie(
            name: 'syncviet_locale',
            value: $locale,
            minutes: 60 * 24 * 365,
            path: '/',
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $locale === 'en'
                ? 'Language switched to English.'
                : 'Đã chuyển ngôn ngữ sang Tiếng Việt.',
        ]);

        return back();
    }
}
