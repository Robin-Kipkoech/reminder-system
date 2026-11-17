<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    /**
     * Updates are restricted to admins and managers; the controller answers 403
     * for a visible item and 404 for one a staff member cannot see.
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
            // Past due dates stay editable so slipping deadlines can be corrected.
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reminder_period_ids' => ['sometimes', 'array'],
            'reminder_period_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('reminder_periods', 'id')->where(function ($query): void {
                    // Periods already on this item stay valid even after they are deactivated.
                    $query->where('is_active', true)
                        ->orWhereIn('id', $this->attachedReminderPeriodIds());
                }),
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

    /**
     * @return array<int, int|string>
     */
    private function attachedReminderPeriodIds(): array
    {
        return DB::table('item_reminder_periods')
            ->where('item_id', $this->route('item'))
            ->pluck('reminder_period_id')
            ->all();
    }
}
