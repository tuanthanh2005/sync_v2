<?php

namespace App\Http\Middleware;

use App\Enums\SanctionType;
use App\Enums\UserStatus;
use App\Models\UserSanction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Enforce account status, auto-expire elapsed sanctions/restrictions, and track activity.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $dirty = false;

            if ($user->status === UserStatus::Suspended && $user->suspended_until !== null && $user->suspended_until->isPast()) {
                $user->forceFill([
                    'status' => UserStatus::Active,
                    'suspended_until' => null,
                ]);
                $dirty = true;

                UserSanction::query()
                    ->where('user_id', $user->id)
                    ->where('type', SanctionType::Suspend->value)
                    ->where('is_active', true)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<=', now())
                    ->update(['is_active' => false]);
            }

            $restrictionMap = [
                'can_post' => ['post_restricted_until', SanctionType::RestrictPost->value],
                'can_comment' => ['comment_restricted_until', SanctionType::RestrictComment->value],
                'can_create_community' => ['community_restricted_until', SanctionType::RestrictCommunity->value],
                'can_message' => ['message_restricted_until', SanctionType::RestrictMessage->value],
                'can_advertise' => ['ad_restricted_until', SanctionType::RestrictAd->value],
            ];

            foreach ($restrictionMap as $flag => [$untilCol, $sanctionType]) {
                $until = $user->getAttribute($untilCol);

                if (! (bool) $user->getAttribute($flag) && $until !== null && $until->isPast()) {
                    $user->forceFill([
                        $flag => true,
                        $untilCol => null,
                    ]);
                    $dirty = true;

                    UserSanction::query()
                        ->where('user_id', $user->id)
                        ->where('type', $sanctionType)
                        ->where('is_active', true)
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '<=', now())
                        ->update(['is_active' => false]);
                }
            }

            if ($dirty) {
                $user->save();
            }

            if (! $user->isActive()) {
                Auth::guard('web')->logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                if ($request->expectsJson()) {
                    abort(403, __('Your account is currently suspended or inactive.'));
                }

                return redirect()
                    ->route('login')
                    ->with('status', __('Your account is currently suspended, banned, or deactivated.'));
            }

            if ($user->last_activity_at === null || $user->last_activity_at->lt(now()->subMinutes(5))) {
                $user->forceFill(['last_activity_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
