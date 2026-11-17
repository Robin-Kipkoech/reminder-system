<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReminderPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'offset_days' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
                'max:365',
                Rule::unique('reminder_periods', 'offset_days')->ignore($this->route('reminder_period')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
