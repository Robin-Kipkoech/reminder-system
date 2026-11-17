<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    protected $fillable = [
        'host', 'port', 'encryption', 'username', 'password',
        'from_address', 'from_name', 'is_enabled', 'updated_by',
    ];

    // Never include the password when the model is turned into JSON.
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',   // encrypt on save, decrypt on read
            'port' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    // There is only ever one row of settings; this fetches it (or null).
    public static function current(): ?self
    {
        return static::query()->first();
    }
}
