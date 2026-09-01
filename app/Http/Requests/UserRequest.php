<?php

namespace App\Http\Requests;

use App\Enums\Permission;
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
            /*
             * Only an admin may mint another admin — otherwise anyone who was
             * granted the Staff screen could promote themselves through a
             * second account.
             */
            'role' => [
                'required',
                Rule::enum(Role::class),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === Role::Admin->value && ! $this->user()->isAdmin()) {
                        $fail('Only an administrator can grant the admin role.');
                    }
                },
            ],

            /*
             * Per-user feature overrides, {"reports": true}. Meaningless for
             * admins (they always hold everything), so it is dropped for an
             * admin target in prepareForValidation.
             */
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['boolean'],

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
        $permissions = $this->input('permissions');

        if (is_array($permissions)) {
            // Unknown keys are dropped rather than rejected — a stale tab
            // sending a key that has since been renamed should not 422.
            $permissions = array_intersect_key(
                array_map(fn ($v) => (bool) $v, $permissions),
                array_flip(Permission::values()),
            );
        }

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'store_id' => $this->input('store_id') ?: null,
            // Admins hold everything regardless, so store no overrides.
            'permissions' => $this->input('role') === Role::Admin->value ? null : $permissions,
        ]);
    }
}
