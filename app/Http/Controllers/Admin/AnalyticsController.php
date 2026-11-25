<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AdminAnalyticsService $analyticsService,
    ) {}

    public function index(Request $request): Response
    {
        $days = (int) $request->input('period', 30);

        return Inertia::render('admin/Analytics', $this->analyticsService->analyticsPayload($days));
    }

    public function health(): Response
    {
        return Inertia::render('admin/SystemHealth', $this->analyticsService->systemHealthPayload());
    }
}
