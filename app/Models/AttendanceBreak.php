<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceBreak extends Model
{
    protected $fillable = [
        'attendance_id',
        'started_at',
        'start_photo_path',
        'start_ip',
        'ended_at',
        'end_photo_path',
        'end_ip',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /** 所要時間（分）。未終了は null */
    public function durationMinutes(): ?int
    {
        if (! $this->ended_at) {
            return null;
        }

        return (int) $this->started_at->diffInMinutes($this->ended_at);
    }
}
