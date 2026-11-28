<?php

namespace App\Services\Social;

use App\Enums\MemberBadgeType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class UserBadgeResolverService
{
    private const CACHE_PREFIX = 'sysnoxis.user_badges.v1.';

    private const CACHE_TTL_MINUTES = 5;

    /**
     * Request-scoped memoization for raw active DB badge records keyed by user ID.
     *
     * @var array<int, list<array{
     *     id: int,
     *     type: string,
     *     grant_source: string,
     *     is_public: bool,
     *     granted_at: string|null,
     *     expires_at: string|null,
     *     member_number: int|null
     * }>>
     */
    private array $dbBadgeMemo = [];

    /**
     * Request-scoped memoization for role slugs keyed by role ID.
     *
     * @var array<int, string>
     */
    private array $roleSlugMemo = [];

    /**
     * Request-scoped memoization for fully resolved user badge summaries keyed by user ID.
     *
     * @var array<int, array{
     *     compact: list<array<string, mixed>>,
     *     profile: list<array<string, mixed>>,
     *     primary: array<string, mixed>|null,
     *     ai: array<string, mixed>|null
     * }>
     */
    private array $resolvedMemo = [];

    /**
     * @return array{
     *     compact: list<array<string, mixed>>,
     *     profile: list<array<string, mixed>>,
     *     primary: array<string, mixed>|null,
     *     ai: array<string, mixed>|null
     * }
     */
    public function resolveForUser(User $user): array
    {
        $map = $this->resolveForUsers([$user]);

        return $map[$user->id] ?? $this->emptyResolution();
    }

    /**
     * Batch-resolve badge payloads for a collection of users with zero N+1 queries.
     *
     * @param  iterable<int|string, User|null>  $users
     * @return array<int, array{
     *     compact: list<array<string, mixed>>,
     *     profile: list<array<string, mixed>>,
     *     primary: array<string, mixed>|null,
     *     ai: array<string, mixed>|null
     * }>
     */
    public function resolveForUsers(iterable $users): array
    {
        /** @var array<int, User> $uniqueUsers */
        $uniqueUsers = [];
        foreach ($users as $u) {
            if ($u instanceof User && $u->id > 0) {
                $uniqueUsers[$u->id] = $u;
            }
        }

        if ($uniqueUsers === []) {
            return [];
        }

        $unmemoizedUserIds = array_values(array_filter(
            array_keys($uniqueUsers),
            fn (int $id): bool => ! isset($this->resolvedMemo[$id]),
        ));

        if ($unmemoizedUserIds !== []) {
            $this->warmRoleSlugs($uniqueUsers);
            $this->warmDbBadges($unmemoizedUserIds);

            foreach ($unmemoizedUserIds as $userId) {
                $this->resolvedMemo[$userId] = $this->computeUserSummary($uniqueUsers[$userId]);
            }
        }

        $result = [];
        foreach (array_keys($uniqueUsers) as $userId) {
            $result[$userId] = $this->resolvedMemo[$userId] ?? $this->emptyResolution();
        }

        return $result;
    }

    /**
     * Convenience accessor matching the legacy activeBadgesForUsers signature.
     *
     * @param  iterable<int|string, User|null>  $users
     * @return array<int, list<array<string, mixed>>>
     */
    public function compactForUsers(iterable $users): array
    {
        $resolved = $this->resolveForUsers($users);
        $out = [];
        foreach ($resolved as $userId => $payload) {
            $out[$userId] = $payload['compact'];
        }

        return $out;
    }

    /**
     * Clear both request memo and persistent cache for a user after any badge, role,
     * status, Premium, or AI account change.
     */
    public function invalidateForUser(int $userId): void
    {
        unset($this->dbBadgeMemo[$userId], $this->resolvedMemo[$userId]);
        Cache::forget(self::CACHE_PREFIX.$userId);
        Cache::forget('syncviet.sidebar.notable_accounts.v1');
    }

    public function flushRequestMemo(): void
    {
        $this->dbBadgeMemo = [];
        $this->roleSlugMemo = [];
        $this->resolvedMemo = [];
    }

    /**
     * @param  array<int, User>  $users
     */
    private function warmRoleSlugs(array $users): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $missingRoleIds = [];
        foreach ($users as $user) {
            if ($user->relationLoaded('rbacRole')) {
                if ($user->rbacRole !== null) {
                    $this->roleSlugMemo[$user->rbacRole->id] = $user->rbacRole->slug;
                }

                continue;
            }

            $roleId = $user->rbac_role_id;
            if ($roleId !== null && ! isset($this->roleSlugMemo[$roleId])) {
                $missingRoleIds[] = (int) $roleId;
            }
        }

        $missingRoleIds = array_values(array_unique($missingRoleIds));
        if ($missingRoleIds === []) {
            return;
        }

        /** @var Collection<int, string> $loaded */
        $loaded = Role::query()
            ->whereIn('id', $missingRoleIds)
            ->pluck('slug', 'id');

        foreach ($loaded as $id => $slug) {
            $this->roleSlugMemo[(int) $id] = (string) $slug;
        }
    }

    /**
     * Batch-load active DB badges for users cleanly using a single IN(...) query.
     *
     * @param  list<int>  $userIds
     */
    private function warmDbBadges(array $userIds): void
    {
        static $schemaFlags = null;
        if ($schemaFlags === null) {
            $hasTable = Schema::hasTable('user_badges');
            $schemaFlags = [
                'table' => $hasTable,
                'status' => $hasTable && Schema::hasColumn('user_badges', 'status'),
                'public' => $hasTable && Schema::hasColumn('user_badges', 'is_public'),
            ];
        }

        if (! $schemaFlags['table']) {
            foreach ($userIds as $id) {
                $this->dbBadgeMemo[$id] = [];
            }

            return;
        }

        $toFetchFromDb = [];
        foreach ($userIds as $id) {
            if (isset($this->dbBadgeMemo[$id])) {
                continue;
            }

            $cached = Cache::get(self::CACHE_PREFIX.$id);
            if (is_array($cached)) {
                /** @var list<array{id: int, type: string, grant_source: string, is_public: bool, granted_at: string|null, expires_at: string|null, member_number: int|null}> $cached */
                $this->dbBadgeMemo[$id] = $cached;

                continue;
            }

            $toFetchFromDb[] = $id;
        }

        if ($toFetchFromDb === []) {
            return;
        }

        $hasStatusCol = $schemaFlags['status'];
        $hasPublicCol = $schemaFlags['public'];

        $query = UserBadge::query()
            ->whereIn('user_id', $toFetchFromDb)
            ->whereNull('revoked_at')
            ->where(static function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id');

        if ($hasStatusCol) {
            $query->where('status', UserBadge::STATUS_ACTIVE);
        }

        if ($hasPublicCol) {
            $query->where('is_public', true);
        }

        $rows = $query->get();

        $grouped = [];
        foreach ($toFetchFromDb as $id) {
            $grouped[$id] = [];
        }

        foreach ($rows as $row) {
            $memberNumber = is_array($row->metadata) && isset($row->metadata['member_number'])
                ? (int) $row->metadata['member_number']
                : null;

            $grouped[$row->user_id][] = [
                'id' => (int) $row->id,
                'type' => (string) $row->badge_type,
                'grant_source' => (string) $row->grant_source,
                'is_public' => $hasPublicCol ? (bool) $row->is_public : true,
                'granted_at' => $row->granted_at->toIso8601String(),
                'expires_at' => $row->expires_at?->toIso8601String(),
                'member_number' => $memberNumber,
            ];
        }

        foreach ($grouped as $userId => $items) {
            $this->dbBadgeMemo[$userId] = $items;
            Cache::put(self::CACHE_PREFIX.$userId, $items, now()->addMinutes(self::CACHE_TTL_MINUTES));
        }
    }

    /**
     * Compute active displayable badges strictly honoring live account state, role eligibility,
     * precedence rules, Gold/Blue non-collision, AI independence, and compact limits.
     *
     * @return array{
     *     compact: list<array<string, mixed>>,
     *     profile: list<array<string, mixed>>,
     *     primary: array<string, mixed>|null,
     *     ai: array<string, mixed>|null
     * }
     */
    private function computeUserSummary(User $user): array
    {
        if (! $user->isActive()) {
            return $this->emptyResolution();
        }

        $roleSlug = $user->rbacRole !== null
            ? $user->rbacRole->slug
            : ($user->rbac_role_id !== null ? ($this->roleSlugMemo[$user->rbac_role_id] ?? null) : null);

        $rawDbRows = $this->dbBadgeMemo[$user->id] ?? [];
        $now = now();

        /** @var array<string, array<string, mixed>> $candidatesByType */
        $candidatesByType = [];

        foreach ($rawDbRows as $row) {
            if (! $row['is_public']) {
                continue;
            }

            $expiresAt = $row['expires_at'] ?? null;
            if (is_string($expiresAt) && $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) <= $now->getTimestamp()) {
                continue;
            }

            $enum = MemberBadgeType::tryFrom((string) $row['type']);
            if ($enum === null) {
                continue;
            }

            // Never grant/display dynamic badges from stale DB rows.
            if ($enum === MemberBadgeType::PremiumMember || $enum === MemberBadgeType::AiAccount) {
                continue;
            }

            // Live role & identity guards for authority badges.
            if ($enum === MemberBadgeType::OfficialStaff && ! $user->isEligibleForOfficialStaffBadge($roleSlug)) {
                continue;
            }

            if ($enum === MemberBadgeType::Moderator && ! $user->isEligibleForModeratorBadge($roleSlug)) {
                continue;
            }

            if (in_array($enum, [
                MemberBadgeType::EarlyMember,
                MemberBadgeType::FoundingMember,
                MemberBadgeType::ReferralAmbassador,
                MemberBadgeType::CommunityBuilder,
            ], true) && ! $user->isHumanMember()) {
                continue;
            }

            // Deduplicate if multiple historical active rows somehow exist; keep latest.
            $candidatesByType[$enum->value] = $this->formatBadge(
                enum: $enum,
                memberNumber: $row['member_number'],
                aiDisclosure: null,
                grantedAt: $row['granted_at'],
            );
        }

        // Dynamic live Premium checkmark (strictly respects expiry and suspension).
        if (! $user->isAiAgent() && $user->isPremium()) {
            $candidatesByType[MemberBadgeType::PremiumMember->value] = $this->formatBadge(
                enum: MemberBadgeType::PremiumMember,
                memberNumber: null,
                aiDisclosure: null,
                grantedAt: null,
            );
        }

        // Independent live AI identification badge (always shown when account_type = ai_agent).
        $aiBadge = null;
        if ($user->isAiAgent()) {
            $aiBadge = $this->formatBadge(
                enum: MemberBadgeType::AiAccount,
                memberNumber: null,
                aiDisclosure: is_string($user->ai_disclosure) && trim($user->ai_disclosure) !== ''
                    ? trim($user->ai_disclosure)
                    : null,
                grantedAt: null,
            );
        }

        // Enforce Gold Checkmark vs Blue Checkmark non-collision:
        // Users with Official Account or Official Staff retain technical Premium features
        // while suppressing Blue Checkmark display so Gold & Blue never sit side-by-side.
        $hasGoldCheckmark = isset($candidatesByType[MemberBadgeType::OfficialAccount->value])
            || isset($candidatesByType[MemberBadgeType::OfficialStaff->value]);

        if ($hasGoldCheckmark) {
            unset($candidatesByType[MemberBadgeType::PremiumMember->value]);
        }

        $primaryCandidates = array_values($candidatesByType);
        usort($primaryCandidates, static fn (array $a, array $b): int => ((int) $b['priority']) <=> ((int) $a['priority']));

        $primary = $primaryCandidates[0] ?? null;

        // Build Compact list (Feed / Comments / Search / Lists / Topbar): strictly max 2 icons.
        $compact = [];
        if ($primary !== null) {
            $compact[] = $primary;
        }

        if ($aiBadge !== null) {
            $compact[] = $aiBadge;
        } elseif (
            $primary !== null
            && $primary['type'] === MemberBadgeType::PremiumMember->value
            && isset($candidatesByType[MemberBadgeType::EarlyMember->value])
        ) {
            $compact[] = $candidatesByType[MemberBadgeType::EarlyMember->value];
        }

        // Profile list: all non-conflicting active badges + AI Account cleanly ordered.
        $profile = $primaryCandidates;
        if ($aiBadge !== null) {
            $profile[] = $aiBadge;
        }

        return [
            'compact' => array_slice($compact, 0, 2),
            'profile' => $profile,
            'primary' => $primary,
            'ai' => $aiBadge,
        ];
    }

    /**
     * @return array{
     *     type: string,
     *     label_key: string,
     *     description_key: string,
     *     icon: string,
     *     color: string,
     *     priority: int,
     *     is_authority: bool,
     *     member_number: int|null,
     *     ai_disclosure: string|null,
     *     granted_at: string|null
     * }
     */
    private function formatBadge(
        MemberBadgeType $enum,
        ?int $memberNumber,
        ?string $aiDisclosure,
        ?string $grantedAt,
    ): array {
        return [
            'type' => $enum->value,
            'label_key' => $enum->labelKey(),
            'description_key' => $enum->descriptionKey(),
            'icon' => $enum->iconKey(),
            'color' => $enum->colorHex(),
            'priority' => $enum->priority(),
            'is_authority' => $enum->isAuthority(),
            'member_number' => $memberNumber,
            'ai_disclosure' => $aiDisclosure,
            'granted_at' => $grantedAt,
        ];
    }

    /**
     * @return array{
     *     compact: list<array<string, mixed>>,
     *     profile: list<array<string, mixed>>,
     *     primary: null,
     *     ai: null
     * }
     */
    private function emptyResolution(): array
    {
        return [
            'compact' => [],
            'profile' => [],
            'primary' => null,
            'ai' => null,
        ];
    }
}
