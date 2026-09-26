<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A vendor and its login in one form. The email is the account's sign-in,
 * so it must be unique across every user — except the vendor's own account
 * when editing.
 */
class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vendor = $this->route('vendor');
        $ownerId = $vendor?->owner?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($ownerId),
            ],
            // Required to create the login; on edit, blank keeps the current one.
            'password' => [
                $vendor && $ownerId ? 'nullable' : 'required',
                'confirmed',
                Password::defaults(),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'contact_name' => $this->input('contact_name') ?: null,
            'phone' => $this->input('phone') ?: null,
            'address' => $this->input('address') ?: null,
            'notes' => $this->input('notes') ?: null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
