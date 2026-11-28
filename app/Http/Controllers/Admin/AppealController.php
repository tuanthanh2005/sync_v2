<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\User;
use App\Notifications\SocialActivityNotification;
use App\Services\Security\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AppealController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->input('status', 'pending');

        $paginator = Appeal::query()
            ->with(['user:id,name,username,status', 'reviewer:id,username', 'sanction:id,type,reason,ends_at,is_active'])
            ->when($status !== 'all', static fn (Builder $q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/Appeals/Index', [
            'appeals' => [
                'data' => array_values(collect($paginator->items())->map(static fn (Appeal $a): array => [
                    'id' => $a->id,
                    'status' => $a->status,
                    'reason' => $a->reason,
                    'resolution_note' => $a->resolution_note,
                    'target_type' => $a->target_type !== null ? class_basename($a->target_type) : null,
                    'target_id' => $a->target_id,
                    'user' => $a->user === null ? null : [
                        'id' => $a->user->id,
                        'name' => $a->user->name,
                        'username' => $a->user->username,
                        'status' => $a->user->status->value,
                    ],
                    'sanction' => $a->sanction === null ? null : [
                        'id' => $a->sanction->id,
                        'type' => $a->sanction->type->value,
                        'type_label' => $a->sanction->type->label(),
                        'reason' => $a->sanction->reason,
                    ],
                    'reviewer_username' => $a->reviewer?->username,
                    'reviewed_at' => $a->reviewed_at?->toIso8601String(),
                    'created_at' => $a->created_at?->toIso8601String(),
                ])->all()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'status' => $status,
        ]);
    }

    public function resolve(Request $request, Appeal $appeal): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'resolution_note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        DB::transaction(function () use ($actor, $appeal, $validated): void {
            $approved = $validated['decision'] === 'approved';

            $appeal->forceFill([
                'status' => $approved ? 'approved' : 'rejected',
                'resolution_note' => trim((string) $validated['resolution_note']),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            if ($approved && $appeal->sanction !== null) {
                $appeal->sanction->forceFill([
                    'is_active' => false,
                    'revoked_at' => now(),
                    'revoked_by' => $actor->id,
                ])->save();

                if ($appeal->user !== null) {
                    $appeal->user->forceFill([
                        'status' => UserStatus::Active,
                        'suspended_until' => null,
                        'ban_reason' => null,
                    ])->save();
                }
            }

            if ($appeal->user !== null) {
                $appeal->user->notify(new SocialActivityNotification(
                    eventKey: 'appeal.resolved',
                    titleVi: $approved ? 'Khiếu nại của bạn đã được chấp thuận.' : 'Khiếu nại của bạn đã bị từ chối.',
                    titleEn: $approved ? 'Your appeal was approved.' : 'Your appeal was rejected.',
                    url: route('dashboard'),
                    actorName: (string) config('app.name', 'SysNoxis'),
                    actorUsername: 'system',
                    titleKey: $approved ? 'Your appeal was approved.' : 'Your appeal was rejected.',
                ));
            }

            $this->auditLogger->log('admin.appeal.resolved', $appeal, ['decision' => $validated['decision']], $actor);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appeal decision recorded.')]);

        return back();
    }
}
