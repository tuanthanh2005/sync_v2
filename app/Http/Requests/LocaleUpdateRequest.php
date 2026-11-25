<?php

namespace App\Http\Requests;

use App\Services\System\LanguageService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocaleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $enabledCodes = app(LanguageService::class)->enabledCodes();

        return [
            'locale' => ['required', 'string', Rule::in($enabledCodes)],
        ];
    }
}
