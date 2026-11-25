<?php

namespace App\Http\Controllers\Admin\Monetization;

use App\Http\Controllers\Controller;
use App\Services\Monetization\RevenueAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class RevenueDashboardController extends Controller
{
    public function __construct(
        private readonly RevenueAnalyticsService $analyticsService,
    ) {}

    public function index(Request $request): Response
    {
        $range = $request->string('range', 'month')->toString();
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        return Inertia::render(
            'admin/Monetization/Revenue',
            $this->analyticsService->dashboardPayload($range, $from, $to),
        )->withViewData([
            'seo' => ['title' => __('Monetization Revenue').' - Admin', 'noindex' => true],
        ]);
    }

    public function exportCsv(Request $request): HttpResponse
    {
        $range = $request->string('range', 'month')->toString();
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $csv = $this->analyticsService->exportMonetizationCsv($range, $from, $to);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="syncviet-monetization-report-'.now()->format('Ymd-His').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
