<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateCharactersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'screenplay' => ['sometimes', 'nullable', 'string', 'min:20'],
            'style' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visual_style' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
