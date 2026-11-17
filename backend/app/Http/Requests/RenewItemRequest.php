<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenewItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The new due date only has to be a valid date: the service compares it with
     * the current one because it needs a row lock to be reliable.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
