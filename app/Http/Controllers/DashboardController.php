<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the authenticated member workspace.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'accountChecklist' => [
                'emailVerified' => $user?->hasVerifiedEmail() ?? false,
                'twoFactorEnabled' => $user?->hasEnabledTwoFactorAuthentication() ?? false,
                'usernameConfigured' => $user !== null && $user->username !== '',
                'timezoneConfigured' => $user !== null && $user->timezone !== '',
            ],
        ]);
    }
}
