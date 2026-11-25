<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string|null $event_id
 * @property string $status
 * @property bool $signature_valid
 * @property string|null $ip_address
 * @property array<string, mixed>|null $payload_summary
 * @property string|null $error_message
 * @property Carbon $created_at
 */
#[Fillable(['provider', 'event_id', 'status', 'signature_valid', 'ip_address', 'payload_summary', 'error_message'])]
class PaymentWebhookLog extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
