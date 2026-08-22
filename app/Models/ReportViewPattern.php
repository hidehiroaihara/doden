<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 帳票の表示パターン（列カスタマイズ）。
 * report_key ごとに、非表示にする列キーの集合を名前付きで保存する。
 */
class ReportViewPattern extends Model
{
    protected $fillable = [
        'report_key',
        'name',
        'hidden_columns',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'hidden_columns' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
