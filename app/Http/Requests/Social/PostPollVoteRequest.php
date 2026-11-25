<?php

namespace App\Http\Requests\Social;

use Illuminate\Foundation\Http\FormRequest;

class PostPollVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'option_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
