<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountType;
use App\Enums\ActivationMilestone;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\UsernameGenerator;
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
        private readonly UsernameGenerator $usernameGenerator,
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

        if (! $this->settings->featureEnabled('feature_google_login') || ! $this->hasProviderCredentials($provider)) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Google sign-in is currently disabled.')]);
        }

        try {
            return Socialite::driver($provider)->redirect();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')
                ->withErrors(['email' => __('Unable to sign in with Google. Please try again.')]);
        }
    }

    /**
     * Handle the OAuth provider callback.
     */
    public function callback(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        if (! $this->settings->featureEnabled('feature_google_login') || ! $this->hasProviderCredentials($provider)) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Google sign-in is currently disabled.')]);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')
                ->withErrors(['email' => __('Unable to sign in with Google. Please try again.')]);
        }

        $email = Str::lower(trim((string) $socialUser->getEmail()));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Unable to sign in with Google. Please try again.')]);
        }

        $providerId = Str::limit(trim((string) $socialUser->getId()), 100, '');
        $avatarUrl = $this->sanitizeAvatarUrl($socialUser->getAvatar());
        $wasCreated = false;
        $registrationPaused = false;

        /** @var User|null $user */
        $user = DB::transaction(function () use (
            $socialUser,
            $provider,
            $email,
            $providerId,
            $avatarUrl,
            &$wasCreated,
            &$registrationPaused,
        ): ?User {
            /** @var User|null $existing */
            $existing = $providerId !== ''
                ? User::withTrashed()
                    ->where('social_provider', $provider)
                    ->where('social_provider_id', $providerId)
                    ->first()
                : null;

            if ($existing === null) {
                $existing = User::withTrashed()
                    ->where('email', $email)
                    ->first();
            }

            if ($existing !== null) {
                if ($existing->trashed()) {
                    return $existing;
                }

                // Merge OAuth identity into existing active account or keep Google avatar synced when no custom upload exists
                if (! $existing->social_provider) {
                    $existing->forceFill([
                        'social_provider' => $provider,
                        'social_provider_id' => $providerId !== '' ? $providerId : null,
                        'avatar_url' => $existing->avatar_url ?? $avatarUrl,
                        'email_verified_at' => $existing->email_verified_at ?? now(),
                    ])->save();
                } elseif (
                    $existing->social_provider === $provider
                    && $avatarUrl !== null
                    && $existing->avatar_url !== null
                    && $existing->avatar_url !== $avatarUrl
                ) {
                    $existing->forceFill(['avatar_url' => $avatarUrl])->save();
                }

                return $existing;
            }

            if (! $this->settings->featureEnabled('feature_registration')) {
                $registrationPaused = true;

                return null;
            }

            // Create a new verified human user with explicit server-enforced defaults
            $wasCreated = true;
            $rawName = trim((string) ($socialUser->getName() ?: $socialUser->getNickname() ?: Str::before($email, '@') ?: 'User'));
            $name = Str::limit($rawName, 120, '');
            $username = $this->usernameGenerator->generate($name);

            $newUser = new User([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => Str::random(40),
                'has_password' => false,
                'timezone' => 'Asia/Ho_Chi_Minh',
                'preferred_locale' => app()->getLocale(),
                'email_verified_at' => now(),
                'social_provider' => $provider,
                'social_provider_id' => $providerId !== '' ? $providerId : null,
                'avatar_url' => $avatarUrl,
            ]);

            $newUser->role = UserRole::User;
            $newUser->status = UserStatus::Active;
            $newUser->account_type = AccountType::Human;
            $newUser->save();

            return $newUser->refresh();
        });

        if ($registrationPaused || $user === null) {
            return redirect()->route('login')
                ->withErrors(['email' => __('New account registrations are currently paused by the administrator.')]);
        }

        if ($wasCreated) {
            try {
                $req = request();
                $this->organicGrowth->attributeNewRegistration($user, $req);
                $this->referralService->attributeRegistration($user, $req);
                $returnTo = $req->hasSession() && is_string($req->session()->get(ReferralService::SESSION_RETURN_KEY))
                    ? (string) $req->session()->get(ReferralService::SESSION_RETURN_KEY)
                    : null;
                $this->onboardingService->ensureProgress($user, $returnTo);
                $this->onboardingService->recordMilestone($user, ActivationMilestone::RegistrationCompleted, 'google_oauth');
                $this->onboardingService->recordMilestone($user, ActivationMilestone::EmailVerified, 'google_oauth');
                $this->earlyMemberService->enrollEarlyMemberIfEligible($user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Block suspended / banned / deactivated / soft-deleted accounts
        if (! $user->isActive()) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Your account is currently suspended, banned, or deactivated.')]);
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

    private function hasProviderCredentials(string $provider): bool
    {
        $clientId = trim((string) config("services.{$provider}.client_id", ''));
        $clientSecret = trim((string) config("services.{$provider}.client_secret", ''));

        return $clientId !== '' && $clientSecret !== '';
    }

    private function sanitizeAvatarUrl(?string $url): ?string
    {
        $trimmed = trim((string) $url);

        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            return null;
        }

        if (! str_starts_with($trimmed, 'https://')) {
            return null;
        }

        return $trimmed;
    }
}
