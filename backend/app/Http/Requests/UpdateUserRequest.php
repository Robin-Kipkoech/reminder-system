<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'manager', 'staff'])],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
        ];
    }
}
