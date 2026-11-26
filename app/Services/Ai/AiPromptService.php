<?php

namespace App\Services\Ai;

use App\Enums\AiPromptType;
use App\Models\AiPromptTemplate;
use App\Models\AiPromptVersion;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiPromptService
{
    /** @var list<string> */
    public const SAFE_VARIABLES = [
        'agent_name',
        'agent_expertise',
        'topic',
        'target_language',
        'community_name',
        'content_type',
        'source_material',
        'writing_style',
        'maximum_length',
        'current_date',
        'seo_context',
    ];

    public function __construct(
        private readonly AiAutomationControlService $control,
    ) {}

    /**
     * Safely interpolate ONLY allowlisted `{{variable}}` tokens (zero PHP/JS/Blade execution)
     * and neutralize prompt-injection attempts inside untrusted external data.
     *
     * @param  array<string, string>  $variables
     * @return array{system_instruction: string, user_prompt: string}
     */
    public function render(AiPromptTemplate $template, array $variables): array
    {
        $allowed = is_array($template->allowed_variables) && $template->allowed_variables !== []
            ? array_values(array_intersect($template->allowed_variables, self::SAFE_VARIABLES))
            : self::SAFE_VARIABLES;

        $system = $template->system_instruction;
        $user = $template->user_template;

        foreach ($allowed as $key) {
            $raw = (string) ($variables[$key] ?? '');
            $safeValue = $key === 'source_material'
                ? $this->sanitizeUntrustedData($raw)
                : trim(strip_tags($raw));

            $placeholder = '{{'.$key.'}}';
            $system = str_replace($placeholder, $safeValue, $system);
            $user = str_replace($placeholder, $safeValue, $user);
        }

        // Remove any unreplaced placeholders cleanly
        $system = preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $system) ?? $system;
        $user = preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $user) ?? $user;

        return [
            'system_instruction' => trim($system),
            'user_prompt' => trim($user),
        ];
    }

    /**
     * Strip prompt-injection control sequences and bound context size for Shared Hosting safety.
     */
    public function sanitizeUntrustedData(string $input, int $maxChars = 6000): string
    {
        $stripped = trim(strip_tags($input));
        $stripped = str_replace(['</reference>', '</source>', '</user_post>', '</content>', '```system'], ' ', $stripped);

        return Str::limit($stripped, $maxChars, '...');
    }

    /**
     * @param  array{
     *     name: string,
     *     prompt_type: string,
     *     agent_id?: int|null,
     *     system_instruction: string,
     *     user_template: string,
     *     changelog?: string|null
     * }  $data
     */
    public function saveTemplate(?AiPromptTemplate $existing, array $data, User $actor): AiPromptTemplate
    {
        $type = AiPromptType::tryFrom($data['prompt_type']);
        if ($type === null) {
            throw ValidationException::withMessages(['prompt_type' => [__('Invalid prompt template type.')]]);
        }

        if ($existing === null) {
            $slug = Str::slug($data['name']).'-'.Str::lower(Str::random(4));
            $template = AiPromptTemplate::query()->create([
                'slug' => $slug,
                'name' => trim($data['name']),
                'prompt_type' => $type,
                'agent_id' => $data['agent_id'] ?? null,
                'system_instruction' => trim($data['system_instruction']),
                'user_template' => trim($data['user_template']),
                'allowed_variables' => self::SAFE_VARIABLES,
                'is_enabled' => true,
                'created_by' => $actor->id,
            ]);
            $nextVer = 1;
        } else {
            $template = $existing;
            $template->forceFill([
                'name' => trim($data['name']),
                'prompt_type' => $type,
                'agent_id' => $data['agent_id'] ?? null,
                'system_instruction' => trim($data['system_instruction']),
                'user_template' => trim($data['user_template']),
            ])->save();
            $nextVer = ((int) AiPromptVersion::query()->where('template_id', $template->id)->max('version_number')) + 1;
        }

        $version = AiPromptVersion::query()->create([
            'template_id' => $template->id,
            'version_number' => $nextVer,
            'system_instruction' => $template->system_instruction,
            'user_template' => $template->user_template,
            'changelog' => trim((string) ($data['changelog'] ?? "Version {$nextVer}")),
            'created_by' => $actor->id,
        ]);

        $template->forceFill(['active_version_id' => $version->id])->save();

        $this->control->logActivity('ai.prompt.saved', $actor, $template->agent_id, AiPromptTemplate::class, $template->id, [
            'slug' => $template->slug,
            'version' => $nextVer,
        ]);

        return $template;
    }

    public function rollbackToVersion(AiPromptTemplate $template, AiPromptVersion $version, User $actor): AiPromptTemplate
    {
        if ($version->template_id !== $template->id) {
            throw ValidationException::withMessages(['version' => [__('Version does not belong to this template.')]]);
        }

        $template->forceFill([
            'active_version_id' => $version->id,
            'system_instruction' => $version->system_instruction,
            'user_template' => $version->user_template,
        ])->save();

        $this->control->logActivity('ai.prompt.rolled_back', $actor, $template->agent_id, AiPromptTemplate::class, $template->id, [
            'restored_version' => $version->version_number,
        ]);

        return $template;
    }
}
