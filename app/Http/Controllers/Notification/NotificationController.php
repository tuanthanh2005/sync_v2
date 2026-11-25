<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Services\Notification\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('Notifications', [
            'notifications' => $this->notificationService->forUser($user, app()->getLocale()),
        ]);
    }

    public function markRead(Request $request, string $notificationId): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->notificationService->markRead($user, $notificationId);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->notificationService->markAllRead($user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('All notifications marked as read.'),
        ]);

        return back();
    }
}
