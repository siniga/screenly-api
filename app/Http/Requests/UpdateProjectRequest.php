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
            'style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'visual_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'style_prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'style_meta' => ['sometimes', 'nullable', 'array'],
            'style_meta.family' => ['sometimes', 'nullable', 'string', 'max:80'],
            'style_meta.medium' => ['sometimes', 'nullable', 'string', 'max:40'],
            'style_meta.variant' => ['sometimes', 'nullable', 'string', 'max:80'],
            'style_reference_url' => ['sometimes', 'nullable', 'string'],
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
