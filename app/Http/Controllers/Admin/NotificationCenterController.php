<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\User;
use App\Services\Admin\AdminNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationCenterController extends Controller
{
    public function __construct(
        private readonly AdminNotificationService $notificationService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Notifications/Index', $this->notificationService->payload());
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:all,staff,verified,user'],
            'target_username' => ['nullable', 'string', 'max:60', 'required_if:audience,user'],
            'category' => ['required', 'string', 'in:system,maintenance,violation,policy'],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:3', 'max:2000'],
            'action_url' => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $this->notificationService->createBroadcast($actor, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Broadcast notification queued.')]);

        return back();
    }

    public function cancel(Request $request, AdminNotification $adminNotification): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->notificationService->cancelScheduled($actor, $adminNotification);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Scheduled notification cancelled.')]);

        return back();
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', 'alpha_dash'],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'in:system,maintenance,violation,policy'],
            'subject_template' => ['required', 'string', 'max:200'],
            'body_template' => ['required', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->notificationService->saveTemplate($actor, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('System settings saved.')]);

        return back();
    }
}
