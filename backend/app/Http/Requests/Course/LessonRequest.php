<?php

namespace App\Http\Requests\Course;

use App\Support\YouTube;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:200'],
            'type' => [$req, Rule::in(['text', 'video', 'document', 'live'])],
            'content' => ['nullable', 'string', 'max:200000'],
            'youtube_url' => ['nullable', 'string', 'max:300', function ($attr, $value, $fail) {
                if ($value !== null && YouTube::extractId($value) === null) {
                    $fail('Enter a valid youtube.com or youtu.be video link.');
                }
            }],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'required_completion_percent' => ['nullable', 'integer', 'between:1,100'],
            'is_required' => ['sometimes', 'boolean'],
            'is_preview' => ['sometimes', 'boolean'],
        ];
    }
}
