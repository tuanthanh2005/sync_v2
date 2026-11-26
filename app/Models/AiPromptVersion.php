<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $template_id
 * @property int $version_number
 * @property string $system_instruction
 * @property string $user_template
 * @property string|null $changelog
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['template_id', 'version_number', 'system_instruction', 'user_template', 'changelog', 'created_by'])]
class AiPromptVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiPromptTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AiPromptTemplate::class, 'template_id');
    }
}
