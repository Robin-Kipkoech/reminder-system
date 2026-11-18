<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    protected $fillable = [
        'host', 'port', 'encryption', 'username', 'password',
        'from_address', 'from_name', 'is_enabled', 'updated_by',
        'reminder_send_time', 'send_overdue_notices', 'last_test_at',
        'last_test_status', 'last_test_error',
    ];

    // Never include the password when the model is turned into JSON.
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',   // encrypt on save, decrypt on read
            'port' => 'integer',
            'is_enabled' => 'boolean',
            'send_overdue_notices' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    // There is only ever one row of settings; this fetches it (or null).
    public static function current(): ?self
    {
        return static::query()->first();
    }
}
