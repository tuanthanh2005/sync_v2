<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Enums\AiPromptType;
use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiModel;
use App\Models\AiPromptTemplate;
use App\Models\AiPromptVersion;
use App\Models\Community;
use App\Models\User;
use App\Rules\ValidUploadedImage;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiAutomationControlService;
use App\Services\Ai\AiPromptService;
use App\Services\Social\ProfileService;
use App\Services\System\LanguageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiAgentsAndPromptsController extends Controller
{
    public function __construct(
        private readonly AiAgentService $agentService,
        private readonly AiPromptService $promptService,
        private readonly AiAutomationControlService $control,
        private readonly LanguageService $languages,
    ) {}

    public function index(): Response
    {
        $agents = AiAgent::query()
            ->with(['user:id,username,status', 'preferredModel:id,model_id,display_name'])
            ->withCount(['drafts'])
            ->orderBy('id')
            ->get()
            ->map(static fn (AiAgent $a): array => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'display_name' => $a->display_name,
                'username' => $a->username,
                'ai_disclosure' => $a->ai_disclosure,
                'system_prompt' => $a->system_prompt,
                'personality' => $a->personality,
                'writing_style' => $a->writing_style,
                'expertise' => $a->expertise,
                'default_language' => $a->default_language,
                'supported_languages' => $a->supported_languages ?? [],
                'allowed_topics' => $a->allowed_topics ?? [],
                'restricted_topics' => $a->restricted_topics ?? [],
                'preferred_model_id' => $a->preferred_model_id,
                'preferred_model_name' => $a->preferredModel?->display_name,
                'daily_post_limit' => $a->daily_post_limit,
                'daily_comment_limit' => $a->daily_comment_limit,
                'monthly_token_budget' => $a->monthly_token_budget,
                'is_enabled' => $a->is_enabled,
                'drafts_count' => (int) ($a->drafts_count ?? 0),
                'profile_url' => route('users.show', $a->username, false),
            ])
            ->values()
            ->all();

        $prompts = AiPromptTemplate::query()
            ->with([
                'agent:id,display_name',
                'versions' => static fn ($q) => $q->orderByDesc('version_number')->limit(10),
            ])
            ->orderBy('id')
            ->get()
            ->map(static fn (AiPromptTemplate $t): array => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
                'prompt_type' => $t->prompt_type->value,
                'prompt_type_label' => $t->prompt_type->label(),
                'agent_id' => $t->agent_id,
                'agent_name' => $t->agent?->display_name,
                'system_instruction' => $t->system_instruction,
                'user_template' => $t->user_template,
                'active_version_id' => $t->active_version_id,
                'is_enabled' => $t->is_enabled,
                'versions' => $t->versions->map(static fn (AiPromptVersion $v): array => [
                    'id' => $v->id,
                    'version_number' => $v->version_number,
                    'changelog' => $v->changelog,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Ai/AgentsAndPrompts', [
            'agents' => $agents,
            'prompts' => $prompts,
            'models' => AiModel::query()->orderBy('is_paid_only')->orderBy('id')->get(['id', 'model_id', 'display_name', 'is_paid_only', 'is_enabled']),
            'communities' => Community::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']),
            'languages' => $this->languages->publicEnabledLanguages(),
            'promptTypes' => array_map(static fn (AiPromptType $t): array => [
                'value' => $t->value,
                'label' => $t->label(),
            ], AiPromptType::cases()),
            'safeVariables' => AiPromptService::SAFE_VARIABLES,
        ]);
    }

    public function seedStarterAgents(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $created = $this->agentService->seedStarterAgents($actor);

        return back()->with('status', __('Starter AI Agents synchronized (:count newly created).', ['count' => $created]));
    }

    public function storeAgent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:48', 'regex:/^[a-z0-9_]+$/'],
            'ai_disclosure' => ['nullable', 'string', 'max:240'],
            'bio' => ['nullable', 'string', 'max:400'],
            'system_prompt' => ['required', 'string', 'min:20', 'max:4000'],
            'personality' => ['nullable', 'string', 'max:250'],
            'writing_style' => ['nullable', 'string', 'max:250'],
            'expertise' => ['nullable', 'string', 'max:250'],
            'default_language' => ['required', 'string', 'max:12'],
            'allowed_topics' => ['nullable', 'string', 'max:600'],
            'restricted_topics' => ['nullable', 'string', 'max:600'],
            'preferred_model_id' => ['nullable', 'integer', 'exists:ai_models,id'],
            'daily_post_limit' => ['required', 'integer', 'min:0', 'max:20'],
            'daily_comment_limit' => ['required', 'integer', 'min:0', 'max:50'],
            'monthly_token_budget' => ['required', 'integer', 'min:10000', 'max:20000000'],
        ]);

        $parseList = static fn (?string $raw): array => array_values(array_filter(
            array_map('trim', explode(',', (string) $raw)),
            static fn (string $v): bool => $v !== '',
        ));

        /** @var User $actor */
        $actor = $request->user();
        $this->agentService->createAgent([
            'display_name' => $validated['display_name'],
            'username' => $validated['username'],
            'ai_disclosure' => $validated['ai_disclosure'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'system_prompt' => $validated['system_prompt'],
            'personality' => $validated['personality'] ?? null,
            'writing_style' => $validated['writing_style'] ?? null,
            'expertise' => $validated['expertise'] ?? null,
            'default_language' => $validated['default_language'],
            'allowed_topics' => $parseList($validated['allowed_topics'] ?? null),
            'restricted_topics' => $parseList($validated['restricted_topics'] ?? null),
            'preferred_model_id' => $validated['preferred_model_id'] ?? null,
            'daily_post_limit' => (int) $validated['daily_post_limit'],
            'daily_comment_limit' => (int) $validated['daily_comment_limit'],
            'monthly_token_budget' => (int) $validated['monthly_token_budget'],
        ], $actor);

        return back()->with('status', __('Official AI Agent account created with transparent AI disclosure.'));
    }

    public function updateAgent(Request $request, AiAgent $agent): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'daily_post_limit' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'daily_comment_limit' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'preferred_model_id' => ['nullable', 'integer', 'exists:ai_models,id'],
            'system_prompt' => ['sometimes', 'string', 'min:20', 'max:4000'],
            'avatar' => ['nullable', 'file', new ValidUploadedImage('ai_agent')],
            'cover' => ['nullable', 'file', new ValidUploadedImage('ai_agent')],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $agentFields = array_diff_key($validated, array_flip(['avatar', 'cover']));
        if ($agentFields !== []) {
            $agent->forceFill($agentFields)->save();
        }

        if (($request->hasFile('avatar') || $request->hasFile('cover')) && $agent->user !== null) {
            app(ProfileService::class)->updateDetails(
                $agent->user,
                [],
                $request->file('avatar'),
                $request->file('cover'),
            );
        }

        $this->control->logActivity('ai.agent.updated', $actor, $agent->id, AiAgent::class, $agent->id, [
            'is_enabled' => $agent->is_enabled,
        ]);

        return back()->with('status', __('AI Agent updated.'));
    }

    public function storePrompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:140'],
            'prompt_type' => ['required', Rule::in(array_map(static fn (AiPromptType $t): string => $t->value, AiPromptType::cases()))],
            'agent_id' => ['nullable', 'integer', 'exists:ai_agents,id'],
            'system_instruction' => ['required', 'string', 'min:10', 'max:6000'],
            'user_template' => ['required', 'string', 'min:10', 'max:6000'],
            'changelog' => ['nullable', 'string', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->promptService->saveTemplate(null, $validated, $actor);

        return back()->with('status', __('Prompt template created and versioned.'));
    }

    public function updatePrompt(Request $request, AiPromptTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:140'],
            'prompt_type' => ['required', Rule::in(array_map(static fn (AiPromptType $t): string => $t->value, AiPromptType::cases()))],
            'agent_id' => ['nullable', 'integer', 'exists:ai_agents,id'],
            'system_instruction' => ['required', 'string', 'min:10', 'max:6000'],
            'user_template' => ['required', 'string', 'min:10', 'max:6000'],
            'changelog' => ['nullable', 'string', 'max:240'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->promptService->saveTemplate($template, $validated, $actor);

        return back()->with('status', __('New prompt template version saved.'));
    }

    public function rollbackPrompt(Request $request, AiPromptTemplate $template, AiPromptVersion $version): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->promptService->rollbackToVersion($template, $version, $actor);

        return back()->with('status', __('Prompt template rolled back to version :ver.', ['ver' => $version->version_number]));
    }
}
