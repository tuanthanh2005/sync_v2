<?php

namespace App\Services\Security;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BrowserSessionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * List active browser sessions safely without exposing raw session identifiers.
     *
     * @return list<array{
     *     is_current_device: bool,
     *     ip_address: string,
     *     user_agent: string,
     *     last_active_at: string
     * }>
     */
    public function activeSessions(User $user, Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $table = (string) config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            return [];
        }

        $currentSessionId = $request->hasSession() ? $request->session()->getId() : '';

        return array_values(
            DB::table($table)
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(static fn (object $session): array => [
                    'is_current_device' => (string) $session->id === $currentSessionId,
                    'ip_address' => (string) ($session->ip_address ?? ''),
                    'user_agent' => (string) ($session->user_agent ?? ''),
                    'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                ])
                ->all(),
        );
    }

    /**
     * List recent authentication events for the given user.
     *
     * @return list<array{
     *     id: int,
     *     event_type: string,
     *     ip_address: string,
     *     user_agent: string,
     *     created_at: string
     * }>
     */
    public function recentLoginHistory(User $user): array
    {
        return array_values(
            LoginHistory::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(10)
                ->get(['id', 'event_type', 'ip_address', 'user_agent', 'created_at'])
                ->map(static fn (LoginHistory $entry): array => [
                    'id' => $entry->id,
                    'event_type' => $entry->event_type,
                    'ip_address' => (string) ($entry->ip_address ?? ''),
                    'user_agent' => (string) ($entry->user_agent ?? ''),
                    'created_at' => $entry->created_at->toIso8601String(),
                ])
                ->all(),
        );
    }

    /**
     * Invalidate all other browser sessions for the authenticated user.
     */
    public function logoutOtherSessions(User $user, string $password, Request $request): void
    {
        Auth::logoutOtherDevices($password);

        if (config('session.driver') === 'database') {
            $table = (string) config('session.table', 'sessions');
            $currentSessionId = $request->hasSession() ? $request->session()->getId() : '';

            if (Schema::hasTable($table)) {
                DB::table($table)
                    ->where('user_id', $user->id)
                    ->where('id', '!=', $currentSessionId)
                    ->delete();
            }
        }

        $this->auditLogger->log('auth.logout_other_sessions', $user, [], $user, $request);
    }
}
