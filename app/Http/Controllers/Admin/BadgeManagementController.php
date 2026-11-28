<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MemberBadgeType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\Admin\AdminBadgeManagementService;
use App\Services\Security\RbacService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BadgeManagementController extends Controller
{
    public function __construct(
        private readonly AdminBadgeManagementService $badgeService,
        private readonly RbacService $rbacService,
    ) {}

    public function index(Request $request): Response
    {
        $this->rbacService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('admin/Users/Badges', $this->badgeService->indexPayload($actor, [
            'search' => (string) $request->input('search', ''),
            'badge_type' => (string) $request->input('badge_type', 'all'),
            'status' => (string) $request->input('status', 'all'),
            'role' => (string) $request->input('role', 'all'),
        ]));
    }

    public function grant(Request $request): RedirectResponse
    {
        $this->rbacService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();
        $this->badgeService->assertCanManageBadges($actor);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'username' => ['nullable', 'string', 'max:60'],
            'badge_type' => [
                'required',
                Rule::in(array_map(
                    static fn (MemberBadgeType $t): string => $t->value,
                    MemberBadgeType::adminManageableCases(),
                )),
            ],
            'reason' => ['required', 'string', 'min:3', 'max:240'],
            'is_public' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if (isset($validated['user_id'])) {
            /** @var User $target */
            $target = User::query()->withTrashed()->findOrFail((int) $validated['user_id']);
        } elseif (isset($validated['username']) && trim((string) $validated['username']) !== '') {
            $cleanUsername = ltrim(Str::lower(trim((string) $validated['username'])), '@');
            /** @var User|null $found */
            $found = User::query()->withTrashed()->where('username', $cleanUsername)->first();
            if ($found === null) {
                return back()->withErrors([
                    'username' => __('No user account matched that username.'),
                ]);
            }
            $target = $found;
        } else {
            return back()->withErrors([
                'username' => __('Please provide a target user account.'),
            ]);
        }

        $expiresAt = is_string($validated['expires_at'] ?? null) && trim($validated['expires_at']) !== ''
            ? CarbonImmutable::parse($validated['expires_at'])
            : null;

        $this->badgeService->grantBadge(
            actor: $actor,
            target: $target,
            type: MemberBadgeType::from((string) $validated['badge_type']),
            reason: (string) $validated['reason'],
            isPublic: array_key_exists('is_public', $validated) ? (bool) $validated['is_public'] : true,
            expiresAt: $expiresAt,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account badge granted.')]);

        return back();
    }

    public function revoke(Request $request, UserBadge $badge): RedirectResponse
    {
        $this->rbacService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();
        $this->badgeService->assertCanManageBadges($actor);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:240'],
        ]);

        $this->badgeService->revokeBadge($actor, $badge, (string) $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account badge revoked.')]);

        return back();
    }

    public function toggleVisibility(Request $request, UserBadge $badge): RedirectResponse
    {
        $this->rbacService->ensureSeeded();

        /** @var User $actor */
        $actor = $request->user();
        $this->badgeService->assertCanManageBadges($actor);

        $validated = $request->validate([
            'is_public' => ['required', 'boolean'],
        ]);

        $this->badgeService->toggleVisibility($actor, $badge, (bool) $validated['is_public']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Badge visibility updated.')]);

        return back();
    }
}
