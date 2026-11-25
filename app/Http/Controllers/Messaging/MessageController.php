<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendMessageRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MessageController extends Controller
{
    public function __construct(
        private readonly MessagingService $messagingService,
    ) {}

    public function index(Request $request, ?Conversation $conversation = null): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        if ($conversation !== null) {
            Gate::authorize('view', $conversation);
        }

        return Inertia::render(
            'Messages',
            $this->messagingService->inboxPayload($user, $conversation),
        );
    }

    public function start(Request $request, User $user): RedirectResponse
    {
        $sender = $request->user();
        abort_if($sender === null, 403);

        $conversation = $this->messagingService->startOrOpenDirect($sender, $user);

        return to_route('messages.show', $conversation);
    }

    public function send(SendMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('send', $conversation);

        $sender = $request->user();
        abort_if($sender === null, 403);

        $this->messagingService->sendMessage($sender, $conversation, $request->string('body')->toString());

        return back();
    }
}
