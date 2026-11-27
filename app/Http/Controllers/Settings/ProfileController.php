<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SeoEntityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Jobs\Seo\ProcessSeoEntityJob;
use App\Models\ReferralCode;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => true,
            'status' => $request->session()->get('status'),
            'pendingEmailChange' => $request->session()->get('settings.pending_email_change'),
            'hasPassword' => (bool) $user->has_password,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Enforce verified OTP email change flow: direct email mutation via PATCH /settings/profile is forbidden.
        if (
            isset($validated['email'])
            && is_string($validated['email'])
            && Str::lower(trim($validated['email'])) !== Str::lower(trim($user->email))
        ) {
            throw ValidationException::withMessages([
                'email' => [__('To change your email address, please use the verified email change section and confirm via OTP.')],
            ]);
        }

        $user->fill(Arr::only($validated, ['name', 'username', 'timezone']));
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile cleanly: revoke all active sessions, opt out of all communications,
     * disable referral codes, remove public profile from SEO sitemap, and audit-log deletion.
     */
    public function destroy(ProfileDeleteRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect('/');
        }

        $userId = $user->id;

        // Opt out of all marketing/retention communications before soft-deleting.
        $user->forceFill([
            'notification_preferences' => [
                'weekly_digest' => false,
                'reengagement' => false,
                'referral_updates' => false,
                'community_updates' => false,
                'marketing_emails' => false,
            ],
        ])->saveQuietly();

        if (Schema::hasTable('referral_codes')) {
            ReferralCode::query()
                ->where('user_id', $userId)
                ->update([
                    'is_active' => false,
                    'disabled_reason' => 'Account deleted by user',
                    'disabled_at' => now(),
                ]);
        }

        $auditLogger->log('account.deleted_by_user', $user, ['user_id' => $userId], $user);

        Auth::logout();

        $user->delete();

        // Terminate ALL remaining sessions across every device for this user.
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $userId)->delete();
        }

        // Remove deleted profile from public SEO URL registry & sitemaps (410 Gone).
        if (Schema::hasTable('seo_url_registry')) {
            ProcessSeoEntityJob::dispatch(SeoEntityType::Profile->value, $userId, true, 410)->afterCommit();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
