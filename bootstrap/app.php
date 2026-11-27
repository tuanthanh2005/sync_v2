<?php

use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\EnsureAdminHasTwoFactor;
use App\Http\Middleware\EnsureAuthenticatedEmailIsVerified;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SeoMiddleware;
use App\Http\Middleware\SeoRedirectMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: ['webhooks/payments/*']);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin.2fa' => EnsureAdminHasTwoFactor::class,
            'permission' => EnsureUserHasPermission::class,
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->prepend(SeoRedirectMiddleware::class);

        $middleware->web(append: [
            SetLocale::class,
            SeoMiddleware::class,
            CheckMaintenanceMode::class,
            EnsureAuthenticatedEmailIsVerified::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $message = __('The uploaded file is too large. Each image must be smaller than :max MB.', ['max' => 40]);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $message,
                    'errors' => [
                        'images' => [$message],
                    ],
                ], 413);
            }

            if ($request->header('X-Inertia') !== null) {
                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => $message,
                ]);

                return back()->withErrors(['images' => $message]);
            }

            return back()->withErrors(['images' => $message])->with('error', $message);
        });
    })->create();
