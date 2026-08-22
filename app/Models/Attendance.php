<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'department_id',
        'work_date',
        'clock_in_at',
        'clock_in_photo_path',
        'clock_in_ip',
        'clock_out_at',
        'clock_out_photo_path',
        'clock_out_ip',
        'break_minutes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function editLogs(): HasMany
    {
        return $this->hasMany(AttendanceEditLog::class);
    }

    public function attendanceBreaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class)->orderBy('started_at');
    }

    /** 完了済み休憩の合計分数（1件以上あれば整数、なければ null） */
    public function completedBreakMinutes(): ?int
    {
        $breaks = $this->attendanceBreaks instanceof Collection
            ? $this->attendanceBreaks
            : $this->attendanceBreaks()->get();

        $completed = $breaks->filter(fn ($b) => $b->ended_at !== null);
        if ($completed->isEmpty()) {
            return null;
        }

        return $completed->sum(fn ($b) => (int) $b->started_at->diffInMinutes($b->ended_at));
    }
}
