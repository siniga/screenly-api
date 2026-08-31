<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateEpisodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'episode_number' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'style' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visual_style' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
