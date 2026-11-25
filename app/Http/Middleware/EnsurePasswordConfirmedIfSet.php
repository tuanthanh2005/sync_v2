<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordConfirmedIfSet
{
    /**
     * Require password confirmation only when the user has already set a password.
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null, ?int $passwordTimeoutSeconds = null): Response
    {
        if ($request->user() && ! $request->user()->has_password) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
