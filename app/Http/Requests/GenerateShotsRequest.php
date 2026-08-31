<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateShotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'style' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visual_style' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
