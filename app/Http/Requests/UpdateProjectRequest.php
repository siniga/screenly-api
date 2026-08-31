<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'style' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visual_style' => ['sometimes', 'nullable', 'string', 'max:100'],
            'story' => ['sometimes', 'nullable', 'string'],
            'story_text' => ['sometimes', 'nullable', 'string'],
            'script' => ['sometimes', 'nullable', 'string'],
            'screenplay' => ['sometimes', 'nullable', 'string'],
            'current_step' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cover_image_url' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
