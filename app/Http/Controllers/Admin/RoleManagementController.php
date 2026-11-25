<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\Security\RbacService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleManagementController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Response
    {
        $this->rbacService->ensureSeeded();

        $roles = Role::query()
            ->with(['permissions:id,slug,module,action,label'])
            ->withCount('users')
            ->orderByDesc('hierarchy_level')
            ->get()
            ->map(static fn (Role $role): array => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'description' => $role->description,
                'hierarchy_level' => $role->hierarchy_level,
                'is_system' => $role->is_system,
                'users_count' => (int) ($role->getAttribute('users_count') ?? 0),
                'permission_ids' => $role->permissions->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
            ])->values()->all();

        $permissions = Permission::query()
            ->orderBy('module')
            ->orderBy('id')
            ->get(['id', 'module', 'action', 'slug', 'label'])
            ->groupBy('module')
            ->map(static fn ($items, string $module): array => [
                'module' => $module,
                'items' => $items->map(static fn (Permission $p): array => [
                    'id' => $p->id,
                    'action' => $p->action,
                    'slug' => $p->slug,
                    'label' => $p->label,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Roles/Index', [
            'roles' => $roles,
            'permissionGroups' => $permissions,
        ]);
    }

    public function syncPermissions(Request $request, Role $role): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer'],
        ]);

        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $validated['permission_ids']));

        $this->rbacService->syncRolePermissions($actor, $role, $ids, $this->auditLogger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role permissions updated.')]);

        return back();
    }
}
