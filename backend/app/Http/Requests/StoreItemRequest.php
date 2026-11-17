<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    /**
     * Creation is restricted to admins and managers, but the check lives in the
     * controller: a forbidden create must answer 403, not the 404 that the
     * policy would produce for a staff member who cannot see the item.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            // An item can cover several departments at once.
            'department_ids' => ['required', 'array', 'min:1'],
            'department_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('departments', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'owner_ids' => ['required', 'array', 'min:1'],
            'owner_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where('status', 'active')->whereNull('deleted_at'),
            ],
            // Past dates are allowed: an already expired licence may be entered.
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reminder_period_ids' => ['sometimes', 'array'],
            'reminder_period_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('reminder_periods', 'id')->where('is_active', true),
            ],
            'is_recurring' => ['required', 'boolean'],
            'recurrence_type' => [
                'nullable',
                'required_if:is_recurring,true',
                Rule::in(Item::RECURRENCE_TYPES),
            ],
            'recurrence_interval' => [
                'nullable',
                'integer',
                'min:1',
                'max:365',
                'required_if:recurrence_type,custom_days',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'department_ids' => 'departments',
            'owner_ids' => 'owners',
            'reminder_period_ids' => 'reminder periods',
        ];
    }
}
