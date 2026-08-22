<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Terminal extends Model
{
    protected $fillable = [
        'name',
        'terminal_id',
        'terminal_key',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function generateKey(): string
    {
        return Str::random(48);
    }
}
