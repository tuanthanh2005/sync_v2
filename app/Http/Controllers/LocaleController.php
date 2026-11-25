<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocaleUpdateRequest;
use App\Services\System\LanguageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;

class LocaleController extends Controller
{
    public function __construct(
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Persist and activate the selected interface language in session, cookie, and user profile.
     */
    public function __invoke(LocaleUpdateRequest $request): RedirectResponse
    {
        /** @var string $locale */
        $locale = $request->validated('locale');

        App::setLocale($locale);
        $request->session()->put('locale', $locale);

        $user = $request->user();

        if ($user !== null && $user->preferred_locale !== $locale) {
            $user->forceFill(['preferred_locale' => $locale])->save();
        }

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

        $nativeName = $locale;

        foreach ($this->languageService->allLanguages() as $lang) {
            if ($lang['code'] === $locale) {
                $nativeName = $lang['native_name'];
                break;
            }
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Language switched to :language.', ['language' => $nativeName]),
        ]);

        return back();
    }
}
