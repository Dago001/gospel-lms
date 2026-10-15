<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // object-level authorisation is enforced by CoursePolicy in the controller
    }

    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:200'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'objectives' => ['sometimes', 'array', 'max:20'],
            'objectives.*' => ['string', 'max:300'],
            'requirements' => ['sometimes', 'array', 'max:20'],
            'requirements.*' => ['string', 'max:300'],
            'level' => ['sometimes', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'duration_minutes_override' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'video_completion_percent' => ['sometimes', 'integer', 'between:1,100'],
            'instructor_ids' => ['sometimes', 'array', 'min:1'],
            'instructor_ids.*' => ['uuid', 'distinct', 'exists:users,id'],
        ];
    }
}
