<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStatusHistory extends Model
{
    protected $fillable = [
        'user_id',
        'changed_by',
        'from_status',
        'to_status',
        'note',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by');
    }

    /** ステータス文字列を日本語に変換 */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active'   => '在籍中',
            'pre_join' => '入社前',
            'retired'  => '退職',
            default    => $status,
        };
    }
}
