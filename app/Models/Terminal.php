<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Terminal extends Model
{
    protected $fillable = [
        'name',
        'terminal_id',
        'terminal_key',
        'department_id',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** 紐付く店舗（部門）。全店共通の端末では null。 */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public static function generateKey(): string
    {
        return Str::random(48);
    }

    /**
     * 店舗に紐付く端末IDを一意に生成する（例: store3-a1b2c3）。
     */
    public static function generateTerminalId(int $departmentId): string
    {
        do {
            $candidate = "store{$departmentId}-".Str::lower(Str::random(6));
        } while (static::where('terminal_id', $candidate)->exists());

        return $candidate;
    }
}
