<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $current_step
 * @property list<string>|null $completed_steps
 * @property list<string>|null $selected_interests
 * @property string|null $selected_locale
 * @property string|null $return_to_url
 * @property CarbonInterface|null $skipped_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'current_step', 'completed_steps', 'selected_interests',
    'selected_locale', 'return_to_url', 'skipped_at', 'completed_at',
])]
class OnboardingProgress extends Model
{
    protected $table = 'onboarding_progress';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'completed_steps' => 'array',
            'selected_interests' => 'array',
            'skipped_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
