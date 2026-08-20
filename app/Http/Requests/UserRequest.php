<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $isCreate = $userId === null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => [
                $isCreate ? 'required' : 'nullable',
                'confirmed',
                Password::defaults(),
            ],
            'role' => ['required', Rule::enum(Role::class)],

            /*
             * store_id is nullable in the schema because admins are not bound
             * to a store, but a cashier without one cannot open /pos at all —
             * there is no way to resolve which stock rows to read. So it is
             * conditionally required on role.
             */
            'store_id' => [
                Rule::requiredIf(fn () => $this->input('role') === Role::Cashier->value),
                'nullable',
                'integer',
                Rule::exists('stores', 'id'),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required' => 'A cashier must be assigned to a store.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'store_id' => $this->input('store_id') ?: null,
        ]);
    }
}
