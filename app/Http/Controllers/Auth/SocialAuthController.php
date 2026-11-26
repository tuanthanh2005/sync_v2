<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActivationMilestone;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Growth\EarlyMemberAndBadgeService;
use App\Services\Growth\OnboardingAndActivationService;
use App\Services\Growth\ReferralService;
use App\Services\Seo\OrganicGrowthService;
use App\Services\System\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly OrganicGrowthService $organicGrowth,
        private readonly ReferralService $referralService,
        private readonly OnboardingAndActivationService $onboardingService,
        private readonly EarlyMemberAndBadgeService $earlyMemberService,
    ) {}

    /**
     * Supported OAuth providers.
     */
    private const ALLOWED_PROVIDERS = ['google'];

    /**
     * Redirect to the OAuth provider.
     */
    public function redirect(string $provider): SymfonyRedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        if (! $this->settings->featureEnabled('feature_google_login')) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Google sign-in is currently disabled.')]);
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the OAuth provider callback.
     */
    public function callback(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        if (! $this->settings->featureEnabled('feature_google_login')) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Google sign-in is currently disabled.')]);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Unable to sign in with Google. Please try again.')]);
        }

        $wasCreated = false;
        $user = DB::transaction(function () use ($socialUser, $provider, &$wasCreated) {
            /** @var User|null $existing */
            $existing = User::where('email', $socialUser->getEmail())->first();

            if ($existing) {
                // Merge OAuth identity into existing account
                if (! $existing->social_provider) {
                    $existing->forceFill([
                        'social_provider' => $provider,
                        'social_provider_id' => $socialUser->getId(),
                        'avatar_url' => $existing->avatar_url ?? $socialUser->getAvatar(),
                        'email_verified_at' => $existing->email_verified_at ?? now(),
                    ])->save();
                }

                return $existing;
            }

            // Create a new user
            $wasCreated = true;
            $name = $socialUser->getName() ?? $socialUser->getNickname() ?? 'User';
            $username = $this->generateUsername($name);

            return User::create([
                'name' => $name,
                'username' => $username,
                'email' => $socialUser->getEmail(),
                'password' => Str::random(40),
                'has_password' => false,
                'email_verified_at' => now(),
                'social_provider' => $provider,
                'social_provider_id' => $socialUser->getId(),
                'avatar_url' => $socialUser->getAvatar(),
            ]);
        });

        if ($wasCreated) {
            $req = request();
            $this->organicGrowth->attributeNewRegistration($user, $req);
            $this->referralService->attributeRegistration($user, $req);
            $this->onboardingService->ensureProgress($user);
            $this->onboardingService->recordMilestone($user, ActivationMilestone::RegistrationCompleted, 'google_oauth');
            $this->onboardingService->recordMilestone($user, ActivationMilestone::EmailVerified, 'google_oauth');
            $this->earlyMemberService->enrollEarlyMemberIfEligible($user);
        }

        // Block suspended / banned / deactivated accounts
        if (! $user->isActive()) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Your account has been suspended.')]);
        }

        // Enforce 2FA challenge even when signing in via Google OAuth
        if ($user->hasEnabledTwoFactorAuthentication()) {
            request()->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => true,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Generate a unique username from the display name.
     */
    private function generateUsername(string $name): string
    {
        $base = Str::slug(Str::limit($name, 20, ''), '_');
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($base)) ?: 'user';

        $username = $base;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.'_'.$counter++;
        }

        return $username;
    }
}
