<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeStoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'story' => ['sometimes', 'nullable', 'string'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
