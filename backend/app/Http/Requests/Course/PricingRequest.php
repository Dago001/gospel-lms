<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;

class PricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'currency' => ['sometimes', 'in:NGN'],
            'discount_price_minor' => ['nullable', 'integer', 'min:0', 'lte:price_minor'],
            'discount_starts_at' => ['nullable', 'date'],
            'discount_ends_at' => ['nullable', 'date', 'after:discount_starts_at'],
        ];
    }
}
