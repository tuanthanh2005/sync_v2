<?php

namespace Tests\Feature\Auth;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\AuthOtpVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_must_verify_email_before_account_activation(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SyncViet#2026Strong!',
            'password_confirmation' => 'SyncViet#2026Strong!',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('verification.notice', absolute: false));
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('pending_registrations', [
            'normalized_email' => 'test@example.com',
            'status' => PendingRegistration::STATUS_PENDING,
        ]);

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

        $verifyResponse = $this->post(route('auth.verify.otp'), [
            'code' => $capturedOtp,
        ]);

        $verifyResponse->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
    }
}
