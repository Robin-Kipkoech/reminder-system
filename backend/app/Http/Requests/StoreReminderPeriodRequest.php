<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReminderPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'offset_days' => ['required', 'integer', 'min:0', 'max:365', Rule::unique('reminder_periods', 'offset_days')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
