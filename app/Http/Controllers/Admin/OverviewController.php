<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\System\PlatformOverviewService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __construct(
        private readonly PlatformOverviewService $overviewService,
    ) {}

    /**
     * Display the role-protected administration foundation view.
     */
    public function __invoke(): Response
    {
        Gate::authorize('access-admin');

        return Inertia::render('admin/Overview', $this->overviewService->adminOverview());
    }
}
