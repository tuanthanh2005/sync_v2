<?php

namespace App\Http\Middleware;

use App\Services\System\SystemSettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    public function __construct(
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Block public routes during scheduled maintenance while keeping Admin access, login, and webhooks reachable.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->settings->get('maintenance_enabled', false)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $user->isStaff() && $user->isActive()) {
            return $next($request);
        }

        if (
            $request->is('login', 'logout', 'two-factor-challenge', 'forgot-password', 'reset-password*', 'locale', 'up', 'admin*', 'webhooks/*')
        ) {
            return $next($request);
        }

        $message = (string) $this->settings->get(
            'maintenance_message',
            'SyncViet is undergoing scheduled maintenance. Please check back shortly.',
        );

        return response($message, 503, [
            'Retry-After' => '300',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
