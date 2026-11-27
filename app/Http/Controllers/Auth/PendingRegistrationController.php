<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PendingRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PendingRegistrationController extends Controller
{
    public function __construct(
        private readonly PendingRegistrationService $pendingRegistrations,
    ) {}

    /**
     * Handle public registration via Pending Registration Architecture.
     * Never grants a full authenticated member session before email verification succeeds.
     */
    public function store(Request $request): RedirectResponse
    {
        $pending = $this->pendingRegistrations->startRegistration($request->all(), $request);

        $request->session()->regenerate();
        $request->session()->forget(PendingRegistrationService::SESSION_UNVERIFIED_USER_ID);
        $request->session()->put(PendingRegistrationService::SESSION_PENDING_TOKEN, $pending->public_token);

        return redirect()
            ->route('verification.notice')
            ->with('status', __('Please verify your email address to complete account registration.'));
    }
}
