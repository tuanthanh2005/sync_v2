<?php

namespace App\Services\Ai;

use App\Models\AiActivityLog;
use App\Models\AiAutomationSetting;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AiAutomationControlService
{
    public const CACHE_KEY = 'syncviet.ai.automation_settings.v1';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        try {
            if (! Schema::hasTable('ai_automation_settings')) {
                return [];
            }

            /** @var array<string, string|null> $cached */
            $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(5), static function (): array {
                return AiAutomationSetting::query()
                    ->pluck('value', 'key')
                    ->map(static fn (mixed $v): ?string => $v === null ? null : (string) $v)
                    ->all();
            });

            return $cached;
        } catch (\Throwable) {
            return [];
        }
    }

    public function bool(string $key, bool $default = false): bool
    {
        $all = $this->all();
        if (! array_key_exists($key, $all) || $all[$key] === null) {
            return $default;
        }

        return in_array(strtolower((string) $all[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $all = $this->all();
        if (! array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }

        return (int) $all[$key];
    }

    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string', ?User $actor = null): void
    {
        $serialized = match ($type) {
            'boolean' => ((bool) $value) ? '1' : '0',
            'integer' => (string) ((int) $value),
            default => $value === null ? null : (string) $value,
        };

        AiAutomationSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $serialized,
                'type' => $type,
                'updated_by' => $actor?->id,
            ],
        );

        Cache::forget(self::CACHE_KEY);
    }

    public function isEmergencyStopped(): bool
    {
        return $this->bool('emergency_stop', false);
    }

    public function triggerEmergencyStop(bool $stop, User $actor, ?string $reason = null): void
    {
        $this->set('emergency_stop', $stop, 'safety', 'boolean', $actor);

        $this->logActivity(
            action: $stop ? 'ai.emergency_stop.activated' : 'ai.emergency_stop.released',
            actor: $actor,
            metadata: ['stopped' => $stop, 'reason' => $reason],
        );
    }

    public function isSwitchEnabled(string $switchKey): bool
    {
        if ($this->isEmergencyStopped()) {
            return false;
        }

        return $this->bool($switchKey, false);
    }

    /**
     * Hard backend enforcement of Emergency Stop and optional switch check.
     */
    public function assertNotStopped(?string $requiredSwitch = null): void
    {
        if ($this->isEmergencyStopped()) {
            throw ValidationException::withMessages([
                'ai' => [__('AI Emergency Stop is active. All automated AI generation, translation, replies, and scheduled publishing are halted.')],
            ]);
        }

        if ($requiredSwitch !== null && ! $this->bool($requiredSwitch, false)) {
            throw ValidationException::withMessages([
                'ai' => [__('This AI automation capability (:switch) is currently disabled.', ['switch' => $requiredSwitch])],
            ]);
        }
    }

    /**
     * Hard internal daily/monthly token & budget gate before sending any external AI API request.
     *
     * @return array{allowed: bool, reason: string|null, tokens_today: int, cost_today_micro_usd: int, cost_month_micro_usd: int}
     */
    public function checkBudgetGate(): array
    {
        $todayStart = now()->startOfDay();
        $monthStart = now()->startOfMonth();

        /** @var \stdClass|null $todayAgg */
        $todayAgg = DB::table('ai_usage_records')
            ->where('created_at', '>=', $todayStart)
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens + thinking_tokens), 0) as tokens, COALESCE(SUM(estimated_cost_micro_usd), 0) as cost_micro')
            ->first();

        /** @var \stdClass|null $monthAgg */
        $monthAgg = DB::table('ai_usage_records')
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(estimated_cost_micro_usd), 0) as cost_micro')
            ->first();

        $tokensToday = (int) ($todayAgg->tokens ?? 0);
        $costTodayMicro = (int) ($todayAgg->cost_micro ?? 0);
        $costMonthMicro = (int) ($monthAgg->cost_micro ?? 0);

        $maxDailyTokens = $this->int('max_daily_tokens', 500000);
        if ($maxDailyTokens > 0 && $tokensToday >= $maxDailyTokens) {
            return [
                'allowed' => false,
                'reason' => "Daily token budget reached ({$tokensToday}/{$maxDailyTokens}).",
                'tokens_today' => $tokensToday,
                'cost_today_micro_usd' => $costTodayMicro,
                'cost_month_micro_usd' => $costMonthMicro,
            ];
        }

        $maxDailyCents = $this->int('max_daily_cost_cents', 0);
        if ($maxDailyCents > 0 && $costTodayMicro >= ($maxDailyCents * 10000)) {
            return [
                'allowed' => false,
                'reason' => 'Daily estimated budget ceiling reached.',
                'tokens_today' => $tokensToday,
                'cost_today_micro_usd' => $costTodayMicro,
                'cost_month_micro_usd' => $costMonthMicro,
            ];
        }

        $maxMonthlyCents = $this->int('max_monthly_cost_cents', 0);
        if ($maxMonthlyCents > 0 && $costMonthMicro >= ($maxMonthlyCents * 10000)) {
            return [
                'allowed' => false,
                'reason' => 'Monthly estimated budget ceiling reached.',
                'tokens_today' => $tokensToday,
                'cost_today_micro_usd' => $costTodayMicro,
                'cost_month_micro_usd' => $costMonthMicro,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'tokens_today' => $tokensToday,
            'cost_today_micro_usd' => $costTodayMicro,
            'cost_month_micro_usd' => $costMonthMicro,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logActivity(
        string $action,
        ?User $actor = null,
        ?int $agentId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $metadata = [],
    ): void {
        if (Schema::hasTable('ai_activity_logs')) {
            AiActivityLog::query()->create([
                'actor_id' => $actor?->id,
                'agent_id' => $agentId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'metadata' => $metadata !== [] ? $metadata : null,
                'created_at' => now(),
            ]);
        }

        if ($actor !== null) {
            $this->auditLogger->log($action, null, $metadata, $actor);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryPayload(): array
    {
        $budget = $this->checkBudgetGate();
        $todayRequests = AiUsageRecord::query()->where('created_at', '>=', now()->startOfDay())->count();

        return [
            'emergency_stop' => $this->isEmergencyStopped(),
            'switches' => [
                'ai_generation_enabled' => $this->bool('ai_generation_enabled', false),
                'ai_publishing_enabled' => $this->bool('ai_publishing_enabled', false),
                'ai_translation_enabled' => $this->bool('ai_translation_enabled', false),
                'ai_replies_enabled' => $this->bool('ai_replies_enabled', false),
                'ai_moderation_enabled' => $this->bool('ai_moderation_enabled', false),
                'ai_research_enabled' => $this->bool('ai_research_enabled', false),
            ],
            'compliance' => [
                'compliance_user_facing_verified' => $this->bool('compliance_user_facing_verified', false),
                'compliance_eea_paid_tier_verified' => $this->bool('compliance_eea_paid_tier_verified', false),
                'require_human_review_before_publish' => $this->bool('require_human_review_before_publish', true),
                'allow_paid_model_fallback' => $this->bool('allow_paid_model_fallback', false),
            ],
            'limits' => [
                'max_generated_drafts_per_day' => $this->int('max_generated_drafts_per_day', 10),
                'max_published_posts_per_day' => $this->int('max_published_posts_per_day', 5),
                'max_translations_per_day' => $this->int('max_translations_per_day', 10),
                'max_ai_replies_per_day' => $this->int('max_ai_replies_per_day', 5),
                'max_ai_replies_per_post' => $this->int('max_ai_replies_per_post', 2),
                'max_daily_tokens' => $this->int('max_daily_tokens', 500000),
                'max_daily_cost_cents' => $this->int('max_daily_cost_cents', 0),
                'max_monthly_cost_cents' => $this->int('max_monthly_cost_cents', 0),
                'max_ai_feed_share_percent' => $this->int('max_ai_feed_share_percent', 30),
            ],
            'usage_today' => [
                'requests' => $todayRequests,
                'tokens' => $budget['tokens_today'],
                'estimated_cost_micro_usd' => $budget['cost_today_micro_usd'],
                'monthly_estimated_cost_micro_usd' => $budget['cost_month_micro_usd'],
                'budget_allowed' => $budget['allowed'],
                'budget_reason' => $budget['reason'],
            ],
        ];
    }
}
