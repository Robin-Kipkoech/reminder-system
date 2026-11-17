<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'manager', 'staff'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ];
    }
}
