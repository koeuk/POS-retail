<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id'),
                // A category cannot be its own parent. Deeper cycles are
                // prevented by the controller before saving.
                Rule::notIn(array_filter([$categoryId])),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_id.not_in' => 'A category cannot be its own parent.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['parent_id' => $this->input('parent_id') ?: null]);
    }
}
