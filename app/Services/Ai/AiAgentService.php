<?php

namespace App\Services\Ai;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiAgent;
use App\Models\AiModel;
use App\Models\User;
use App\Services\Social\ProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiAgentService
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly AiAutomationControlService $control,
    ) {}

    /**
     * @param  array{
     *     display_name: string,
     *     username: string,
     *     bio?: string|null,
     *     ai_disclosure?: string|null,
     *     system_prompt: string,
     *     personality?: string|null,
     *     writing_style?: string|null,
     *     expertise?: string|null,
     *     default_language?: string,
     *     supported_languages?: list<string>|null,
     *     assigned_community_ids?: list<int>|null,
     *     allowed_topics?: list<string>|null,
     *     restricted_topics?: list<string>|null,
     *     preferred_model_id?: int|null,
     *     daily_post_limit?: int,
     *     daily_comment_limit?: int,
     *     monthly_token_budget?: int
     * }  $data
     */
    public function createAgent(array $data, User $actor): AiAgent
    {
        $maxAgents = max(1, (int) config('syncviet.ai.max_agents_limit', 30));
        if (AiAgent::query()->count() >= $maxAgents) {
            throw ValidationException::withMessages([
                'display_name' => [__('Maximum configured AI Agent limit (:max) reached.', ['max' => $maxAgents])],
            ]);
        }

        $username = Str::lower(trim($data['username']));
        if (User::query()->where('username', $username)->exists() || AiAgent::query()->where('username', $username)->exists()) {
            throw ValidationException::withMessages([
                'username' => [__('This username is already taken.')],
            ]);
        }

        $disclosure = trim((string) ($data['ai_disclosure'] ?? 'Official SyncViet AI Agent — Automated Assistant'));

        return DB::transaction(function () use ($data, $actor, $username, $disclosure): AiAgent {
            // Strictly standard UserRole::User, AccountType::AiAgent, zero admin privileges, zero Premium Blue Badge impersonation
            $user = new User([
                'name' => trim($data['display_name']),
                'username' => $username,
                'email' => "ai.{$username}@agents.syncviet.invalid",
                'password' => Str::password(40),
                'has_password' => false,
                'timezone' => 'UTC',
                'preferred_locale' => $data['default_language'] ?? 'en',
                'email_verified_at' => now(),
            ]);
            $user->role = UserRole::User;
            $user->account_type = AccountType::AiAgent;
            $user->ai_disclosure = $disclosure;
            $user->status = UserStatus::Active;
            $user->save();

            $profile = $this->profileService->getOrCreateProfile($user);
            $profile->forceFill([
                'headline' => $disclosure,
                'bio' => trim((string) ($data['bio'] ?? ($data['expertise'] ?? $disclosure))),
                'is_verified_badge' => false,
            ])->save();

            $agent = AiAgent::query()->create([
                'user_id' => $user->id,
                'display_name' => trim($data['display_name']),
                'username' => $username,
                'ai_disclosure' => $disclosure,
                'system_prompt' => trim($data['system_prompt']),
                'personality' => isset($data['personality']) ? trim($data['personality']) : null,
                'writing_style' => isset($data['writing_style']) ? trim($data['writing_style']) : null,
                'expertise' => isset($data['expertise']) ? trim($data['expertise']) : null,
                'default_language' => $data['default_language'] ?? 'en',
                'supported_languages' => $data['supported_languages'] ?? ['en', 'vi'],
                'assigned_community_ids' => $data['assigned_community_ids'] ?? [],
                'allowed_topics' => $data['allowed_topics'] ?? [],
                'restricted_topics' => $data['restricted_topics'] ?? [],
                'preferred_model_id' => $data['preferred_model_id'] ?? AiModel::query()->where('model_id', 'gemini-2.5-flash')->value('id'),
                'daily_post_limit' => max(0, min(20, (int) ($data['daily_post_limit'] ?? 2))),
                'daily_comment_limit' => max(0, min(50, (int) ($data['daily_comment_limit'] ?? 5))),
                'monthly_token_budget' => max(10000, (int) ($data['monthly_token_budget'] ?? 500000)),
                'is_enabled' => true,
                'created_by' => $actor->id,
            ]);

            $this->control->logActivity('ai.agent.created', $actor, $agent->id, AiAgent::class, $agent->id, [
                'username' => $agent->username,
                'default_language' => $agent->default_language,
            ]);

            return $agent;
        });
    }

    /**
     * Provision the 7 recommended starter official SyncViet AI Agents idempotently.
     */
    public function seedStarterAgents(User $actor): int
    {
        $starters = [
            [
                'display_name' => 'SyncViet Assistant',
                'username' => 'syncviet_assistant',
                'expertise' => 'SyncViet platform onboarding, community features, and help guides',
                'personality' => 'Friendly, clear, transparent official AI assistant',
                'writing_style' => 'Concise, structured, step-by-step',
                'allowed_topics' => ['SyncViet Guides', 'Community Tips', 'Getting Started'],
                'system_prompt' => 'You are SyncViet Assistant, an official transparent AI Agent helping members learn SyncViet features. Never claim to be human or promise financial/policy overrides.',
            ],
            [
                'display_name' => 'AI Explorer',
                'username' => 'ai_explorer',
                'expertise' => 'Artificial Intelligence tools, prompt engineering, and practical workflows',
                'personality' => 'Curious, analytical, practical AI educator',
                'writing_style' => 'Structured tutorials with concrete examples',
                'allowed_topics' => ['Artificial Intelligence', 'AI Tools', 'Prompt Engineering'],
                'system_prompt' => 'You are AI Explorer, an official AI Agent sharing practical tutorials and comparisons on AI tools. Cite verified sources only and never invent benchmarks.',
            ],
            [
                'display_name' => 'Tech Daily',
                'username' => 'tech_daily',
                'expertise' => 'Technology trends, software releases, and verified product updates',
                'personality' => 'Objective, fact-focused tech editor',
                'writing_style' => 'Clear summaries with verified source attribution',
                'allowed_topics' => ['Technology', 'Software', 'Digital Products'],
                'system_prompt' => 'You are Tech Daily, an official AI Agent summarizing verified technology documentation and product releases. Never invent breaking news without verified source material.',
            ],
            [
                'display_name' => 'Developer AI',
                'username' => 'developer_ai',
                'expertise' => 'Software engineering, PHP/Laravel, TypeScript/Vue, databases, and debugging',
                'personality' => 'Pragmatic senior software engineer AI assistant',
                'writing_style' => 'Technical, accurate, clean code snippets',
                'allowed_topics' => ['Programming', 'Web Development', 'Databases', 'Architecture'],
                'system_prompt' => 'You are Developer AI, an official AI Agent helping developers solve engineering challenges with accurate patterns and clear explanations.',
            ],
            [
                'display_name' => 'Business Insights',
                'username' => 'business_insights',
                'expertise' => 'Online business, SaaS growth, marketing frameworks, and startups',
                'personality' => 'Measured, evidence-based business analyst AI',
                'writing_style' => 'Actionable frameworks and honest discussion prompts',
                'allowed_topics' => ['Online Business', 'Startup', 'Marketing'],
                'system_prompt' => 'You are Business Insights, an official AI Agent sharing startup and digital product frameworks. Never promise guaranteed profits or give licensed financial advice.',
            ],
            [
                'display_name' => 'Crypto Knowledge',
                'username' => 'crypto_knowledge',
                'expertise' => 'Blockchain fundamentals, cryptography education, and sourced protocol mechanics',
                'personality' => 'Neutral educational explainer AI',
                'writing_style' => 'Educational, balanced, strictly non-financial-advice',
                'allowed_topics' => ['Blockchain Education', 'Cryptography', 'Web3 Security'],
                'system_prompt' => 'You are Crypto Knowledge, an official AI Agent explaining blockchain technology and security concepts objectively. Strictly educational — never provide trading signals, price predictions, or financial advice.',
            ],
            [
                'display_name' => 'Knowledge Hub',
                'username' => 'knowledge_hub',
                'expertise' => 'General learning, productivity, critical thinking, and open community discussions',
                'personality' => 'Thoughtful, welcoming community facilitator AI',
                'writing_style' => 'Clear explainers and open-ended discussion questions',
                'allowed_topics' => ['Productivity', 'Learning', 'General Knowledge'],
                'system_prompt' => 'You are Knowledge Hub, an official AI Agent sharing clear explainers and thoughtful questions to spark healthy human discussions across languages.',
            ],
        ];

        $created = 0;
        foreach ($starters as $item) {
            if (AiAgent::query()->where('username', $item['username'])->exists() || User::query()->where('username', $item['username'])->exists()) {
                continue;
            }
            $this->createAgent($item, $actor);
            $created++;
        }

        return $created;
    }
}
