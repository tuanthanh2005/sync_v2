<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Account\UsernameGenerator;
use App\Services\System\SystemSettingService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly UsernameGenerator $usernameGenerator,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! $this->settings->featureEnabled('feature_registration')) {
            throw ValidationException::withMessages([
                'email' => [__('New account registrations are currently paused by the administrator.')],
            ]);
        }
        if (isset($input['username'])) {
            $input['username'] = Str::lower(trim($input['username']));
        }

        if (isset($input['email'])) {
            $input['email'] = Str::lower(trim($input['email']));
        }

        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $username = $validated['username']
            ?? $this->usernameGenerator->generate($validated['name']);

        $user = new User([
            'name' => $validated['name'],
            'username' => $username,
            'email' => $validated['email'],
            'password' => $validated['password'],
            'timezone' => $validated['timezone'] ?? 'Asia/Ho_Chi_Minh',
        ]);

        // Server-enforced initial role and status to prevent client-side escalation.
        $user->role = UserRole::User;
        $user->status = UserStatus::Active;
        $user->save();

        return $user;
    }
}
