<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attendance_id',
        'field_name',
        'before_value',
        'after_value',
        'modified_by_user_id',
        'modified_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'modified_at' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'modified_by_user_id');
    }
}
