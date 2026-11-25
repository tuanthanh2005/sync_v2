<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\FooterLink;
use App\Models\Language;
use App\Models\LoginHistory;
use App\Models\PaymentWebhookLog;
use App\Models\PolicyPage;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminSecurityService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{tab?: string, search?: string, action?: string}  $filters
     * @return array<string, mixed>
     */
    public function securityPayload(array $filters): array
    {
        $tab = (string) ($filters['tab'] ?? 'audits');
        $search = trim((string) ($filters['search'] ?? ''));
        $actionFilter = trim((string) ($filters['action'] ?? ''));

        $auditsQuery = AuditLog::query()->with(['actor:id,username']);

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $auditsQuery->where(static function (Builder $q) use ($pattern): void {
                $q->where('action', 'like', $pattern)
                    ->orWhere('ip_address', 'like', $pattern)
                    ->orWhereHas('actor', static fn (Builder $aq) => $aq->where('username', 'like', $pattern));
            });
        }

        if ($actionFilter !== '') {
            $auditsQuery->where('action', 'like', addcslashes($actionFilter, '\\%_').'%');
        }

        $audits = $auditsQuery->latest('id')->paginate(30)->withQueryString();

        $logins = LoginHistory::query()
            ->with(['user:id,username'])
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(static fn (LoginHistory $h): array => [
                'id' => $h->id,
                'username' => $h->user?->username,
                'email_attempted' => $h->email_attempted,
                'event_type' => $h->event_type,
                'ip_address' => $h->ip_address,
                'user_agent' => Str::limit((string) $h->user_agent, 120),
                'created_at' => $h->created_at->toIso8601String(),
            ])->values()->all();

        $suspicious = DB::table('login_histories')
            ->where('event_type', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw('ip_address, COUNT(*) as attempts, MAX(created_at) as last_attempt')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('attempts')
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'ip_address' => (string) ($row->ip_address ?? '-'),
                'attempts' => (int) $row->attempts,
                'last_attempt' => (string) $row->last_attempt,
            ])->values()->all();

        $sessions = Schema::hasTable('sessions')
            ? DB::table('sessions')
                ->leftJoin('users', 'users.id', '=', 'sessions.user_id')
                ->whereNotNull('sessions.user_id')
                ->orderByDesc('sessions.last_activity')
                ->limit(40)
                ->get(['sessions.id', 'sessions.user_id', 'users.username', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'user_id' => (int) $row->user_id,
                    'username' => (string) ($row->username ?? '-'),
                    'ip_address' => (string) ($row->ip_address ?? '-'),
                    'user_agent' => Str::limit((string) ($row->user_agent ?? ''), 100),
                    'last_active_at' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                ])->values()->all()
            : [];

        $webhooks = Schema::hasTable('payment_webhook_logs')
            ? PaymentWebhookLog::query()->latest('id')->limit(40)->get()->map(static fn (PaymentWebhookLog $w): array => [
                'id' => $w->id,
                'provider' => $w->provider,
                'event_id' => $w->event_id,
                'status' => $w->status,
                'signature_valid' => $w->signature_valid,
                'ip_address' => $w->ip_address,
                'error_message' => $w->error_message,
                'created_at' => $w->created_at->toIso8601String(),
            ])->values()->all()
            : [];

        return [
            'tab' => $tab,
            'filters' => ['search' => $search, 'action' => $actionFilter],
            'audits' => [
                'data' => array_values(collect($audits->items())->map(static fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor_username' => $log->actor?->username,
                    'auditable_type' => $log->auditable_type !== null ? class_basename($log->auditable_type) : null,
                    'auditable_id' => $log->auditable_id,
                    'ip_address' => $log->ip_address,
                    'metadata' => $log->metadata,
                    'created_at' => $log->created_at->toIso8601String(),
                ])->all()),
                'current_page' => $audits->currentPage(),
                'last_page' => $audits->lastPage(),
                'total' => $audits->total(),
            ],
            'logins' => $logins,
            'suspicious' => $suspicious,
            'sessions' => $sessions,
            'webhooks' => $webhooks,
        ];
    }

    public function revokeSession(User $actor, string $sessionId): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('id', $sessionId)->delete();
        }

        $this->auditLogger->log('security.session_revoked', null, ['session_hash' => sha1($sessionId)], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function backupsPayload(): array
    {
        $items = BackupRecord::query()
            ->with(['creator:id,username'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(static fn (BackupRecord $b): array => [
                'id' => $b->id,
                'type' => $b->type,
                'disk' => $b->disk,
                'path' => basename($b->path),
                'size_bytes' => $b->size_bytes,
                'checksum' => $b->checksum,
                'status' => $b->status,
                'notes' => $b->notes,
                'creator_username' => $b->creator?->username,
                'created_at' => $b->created_at->toIso8601String(),
            ])->values()->all();

        return ['backups' => $items];
    }

    /**
     * Create a Hostinger-safe portable JSON governance & metadata snapshot with SHA-256 checksum.
     */
    public function createSnapshot(User $actor, ?string $notes = null): BackupRecord
    {
        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'counts' => [
                'users' => User::query()->withTrashed()->count(),
                'posts' => DB::table('posts')->count(),
                'comments' => DB::table('comments')->count(),
                'communities' => DB::table('communities')->count(),
                'payments' => DB::table('payments')->count(),
                'ad_campaigns' => DB::table('ad_campaigns')->count(),
            ],
            'roles' => Role::query()->with('permissions:id,slug')->get()->toArray(),
            'languages' => Language::query()->orderBy('sort_order')->get()->toArray(),
            'system_settings' => SystemSetting::query()->get(['group', 'key', 'value', 'type', 'is_public'])->toArray(),
            'policy_pages' => PolicyPage::query()->get(['slug', 'title_vi', 'title_en', 'status', 'is_visible', 'footer_group'])->toArray(),
            'footer_links' => FooterLink::query()->get(['label_vi', 'label_en', 'url', 'footer_group', 'is_visible'])->toArray(),
        ];

        $json = (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $checksum = hash('sha256', $json);
        $filename = 'backups/syncviet-snapshot-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.json';

        Storage::disk('local')->put($filename, $json);

        $record = new BackupRecord([
            'type' => 'governance_snapshot',
            'disk' => 'local',
            'path' => $filename,
            'size_bytes' => strlen($json),
            'checksum' => $checksum,
            'status' => 'completed',
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ]);
        $record->created_by = $actor->id;
        $record->created_at = now();
        $record->save();

        $this->auditLogger->log('admin.backup.created', $record, ['size_bytes' => $record->size_bytes, 'checksum' => $checksum], $actor);

        return $record;
    }
}
