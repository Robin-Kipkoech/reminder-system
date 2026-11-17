<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    /**
     * Any authenticated user may create a task and assign any active user.
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
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'visibility' => ['required', Rule::in(Task::VISIBILITIES)],
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where('status', 'active')->whereNull('deleted_at'),
            ],
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
                Rule::in(Task::RECURRENCE_TYPES),
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
            'assignee_ids' => 'assignees',
            'reminder_period_ids' => 'reminder periods',
        ];
    }
}
