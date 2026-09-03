<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', 'string', 'max:120'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:40'],
            'age' => ['sometimes', 'nullable', 'string', 'max:40'],
            'age_range' => ['sometimes', 'nullable', 'string', 'max:40'],
            'ethnicity' => ['sometimes', 'nullable', 'string', 'max:80'],
            'appearance' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'wardrobe' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'personality' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
