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

    public function after(): array
    {
        return [function ($validator) {
            if ($this->filled('parent_id') && $this->route('category')?->children()->exists()) {
                $validator->errors()->add('parent_id', 'A category with subcategories cannot become a subcategory.');
            }
        }];
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('parent_id'), Rule::notIn(array_filter([$this->route('category')?->id]))],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
