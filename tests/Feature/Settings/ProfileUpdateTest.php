<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\AuthOtpVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated_without_mutating_email(): void
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $originalEmail,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame($originalEmail, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_direct_email_change_via_profile_update_is_rejected(): void
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'hijack@example.com',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('profile.edit'));

        $this->assertSame($originalEmail, $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_verified_email_change_stores_email_in_lowercase_so_user_can_still_log_in(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('settings.email.change-request'), [
                'new_email' => 'Test.Upper@Example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $capturedOtp = null;
        Notification::assertSentOnDemand(
            AuthOtpVerificationNotification::class,
            function (AuthOtpVerificationNotification $notification, array $channels, object $notifiable) use (&$capturedOtp): bool {
                $mail = $notification->toMail($notifiable);
                foreach ($mail->introLines as $line) {
                    if (is_string($line) && preg_match('/\*\*(\d{6})\*\*/', $line, $m) === 1) {
                        $capturedOtp = $m[1];
                    }
                }

                return true;
            },
        );

        $this->assertNotNull($capturedOtp);

        $this
            ->actingAs($user)
            ->post(route('settings.email.verify-otp'), [
                'new_email' => 'Test.Upper@Example.com',
                'code' => $capturedOtp,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('test.upper@example.com', $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'Test.Upper@Example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_must_be_unique_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('settings.email.change-request'), [
                'new_email' => 'Taken@Example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasErrors('new_email')
            ->assertRedirect(route('profile.edit'));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertSoftDeleted($user);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
