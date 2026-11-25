<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SanctionUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isActive() && ($user->hasPermission('users.suspend') || $user->hasPermission('users.ban'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                'in:warn,suspend,ban,unban,unsuspend,restrict_post,restrict_comment,restrict_community,restrict_message,restrict_ad,lift_restriction',
            ],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'duration' => ['nullable', 'string', 'in:1h,24h,3d,7d,30d,custom,permanent'],
            'until' => ['nullable', 'date', 'after:now'],
            'capability' => ['nullable', 'string', 'in:post,comment,community,message,advertise'],
        ];
    }
}
