<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SanctionUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Admin\AdminUserService;
use App\Services\Security\AuditLogger;
use App\Services\Security\RbacService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly AdminUserService $userService,
        private readonly RbacService $rbacService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        $this->rbacService->ensureSeeded();

        return Inertia::render('admin/Users/Index', $this->userService->listPayload([
            'filter' => (string) $request->input('filter', 'all'),
            'search' => (string) $request->input('search', ''),
            'role' => (string) $request->input('role', ''),
            'sort' => (string) $request->input('sort', 'created_at'),
            'dir' => (string) $request->input('dir', 'desc'),
        ]));
    }

    public function show(Request $request, int $userId): Response
    {
        $this->rbacService->ensureSeeded();
        /** @var User $target */
        $target = User::query()->withTrashed()->findOrFail($userId);

        return Inertia::render('admin/Users/Show', $this->userService->detailPayload($target, $request->user()));
    }

    public function update(Request $request, int $userId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->withTrashed()->findOrFail($userId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:40', 'alpha_dash', Rule::unique('users', 'username')->ignore($target->id)],
            'timezone' => ['required', 'timezone:all'],
            'is_verified_badge' => ['sometimes', 'boolean'],
        ]);

        $this->userService->updateAccount($actor, $target, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User account updated.')]);

        return back();
    }

    public function sanction(SanctionUserRequest $request, int $userId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->withTrashed()->findOrFail($userId);

        $data = $request->validated();

        $this->userService->applySanction(
            actor: $actor,
            target: $target,
            action: (string) $data['action'],
            reason: (string) $data['reason'],
            duration: isset($data['duration']) ? (string) $data['duration'] : null,
            customUntil: isset($data['until']) ? (string) $data['until'] : null,
            capability: isset($data['capability']) ? (string) $data['capability'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User sanction applied.')]);

        return back();
    }

    public function updateRole(UpdateUserRoleRequest $request, int $userId): RedirectResponse
    {
        $this->rbacService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->withTrashed()->findOrFail($userId);

        /** @var Role $role */
        $role = Role::query()->where('slug', $request->string('role')->toString())->firstOrFail();

        $this->rbacService->assignRole($actor, $target, $role, $this->auditLogger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User role updated.')]);

        return back();
    }

    public function revokeSessions(Request $request, int $userId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->withTrashed()->findOrFail($userId);

        $this->userService->revokeSessions($actor, $target);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Active sessions revoked.')]);

        return back();
    }

    public function sendPasswordReset(Request $request, int $userId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->withTrashed()->findOrFail($userId);

        $this->userService->sendPasswordReset($actor, $target);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password reset link sent.')]);

        return back();
    }

    public function lifecycle(Request $request, int $userId): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::query()->withTrashed()->findOrFail($userId);

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:deactivate,soft_delete,restore'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->userService->manageLifecycle($actor, $target, (string) $validated['mode'], (string) $validated['reason']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['mode'] === 'restore' ? __('Account restored.') : __('User account updated.'),
        ]);

        return back();
    }

    public function verifications(Request $request): Response
    {
        return Inertia::render(
            'admin/Users/Verifications',
            $this->userService->verificationsPayload((string) $request->input('status', 'pending')),
        );
    }

    public function reviewVerification(Request $request, VerificationRequest $verificationRequest): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->userService->reviewVerification(
            $actor,
            $verificationRequest,
            (string) $validated['decision'],
            isset($validated['note']) ? (string) $validated['note'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Verification request reviewed.')]);

        return back();
    }
}
