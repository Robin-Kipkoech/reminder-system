<?php

namespace App\Models;

use Database\Factories\ReminderPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['label', 'offset_days', 'is_active'])]
class ReminderPeriod extends Model
{
    /** @use HasFactory<ReminderPeriodFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
