<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * 勤怠・締めの会社共通設定の保存のみを担当。
 * 表示は「基本設定 > 勤怠設定」タブ（PayrollSettingController）に統合済み。
 */
class SettingController extends Controller
{
    public function update(Request $request)
    {
        $hasAnyWork = $request->filled('work_start_time')
            || $request->filled('work_end_time')
            || $request->filled('work_hours_per_day');

        $workRules = $hasAnyWork
            ? [
                'work_start_time' => ['required', 'date_format:H:i'],
                'work_end_time' => ['required', 'date_format:H:i', 'after:work_start_time'],
                'work_hours_per_day' => ['required', 'integer', 'min:1', 'max:1440'],
            ]
            : [
                'work_start_time' => ['nullable'],
                'work_end_time' => ['nullable'],
                'work_hours_per_day' => ['nullable'],
            ];

        $dows = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        $validated = $request->validate(array_merge([
            'default_break_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'break_start_time' => ['required', 'date_format:H:i'],
            'break_end_time' => ['required', 'date_format:H:i', 'after:break_start_time'],
            'salary_round_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'salary_round_rule' => ['required', 'in:floor,round,ceil'],
            'month_closing_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            // 打刻時に顔写真（カメラ・顔認識）を使用するか
            'punch_use_photo' => ['nullable', 'boolean'],
            'punch_day_boundary_hour' => ['nullable', 'integer', 'min:0', 'max:6'],
            // 休日区分（年度設定未作成時のフォールバック。判定ロジックは AttendanceSummaryService と共通）
            'legal_holiday_dows' => ['nullable', 'array'],
            'legal_holiday_dows.*' => ['in:'.implode(',', $dows)],
            'prescribed_holiday_dows' => ['nullable', 'array'],
            'prescribed_holiday_dows.*' => ['in:'.implode(',', $dows)],
        ], $workRules));

        Setting::setValue('default_break_minutes', $validated['default_break_minutes']);
        Setting::setValue('break_start_time', $validated['break_start_time']);
        Setting::setValue('break_end_time', $validated['break_end_time']);
        Setting::setValue('salary_round_minutes', $validated['salary_round_minutes']);
        Setting::setValue('salary_round_rule', $validated['salary_round_rule']);

        Setting::setValue('punch_use_photo', $request->boolean('punch_use_photo') ? '1' : '0');

        $boundaryHour = isset($validated['punch_day_boundary_hour'])
            ? max(0, min(6, (int) $validated['punch_day_boundary_hour']))
            : 5;
        Setting::setValue('punch_day_boundary_hour', (string) $boundaryHour);

        Setting::setValue('work_start_time', $validated['work_start_time'] ?: null);
        Setting::setValue('work_end_time', $validated['work_end_time'] ?: null);
        Setting::setValue('work_hours_per_day', $validated['work_hours_per_day'] ?: null);

        Setting::setValue(
            'month_closing_day',
            isset($validated['month_closing_day']) && $validated['month_closing_day'] !== null
                ? (int) $validated['month_closing_day']
                : null,
        );

        Setting::setValue('legal_holiday_dows', implode(',', $validated['legal_holiday_dows'] ?? []));
        Setting::setValue('prescribed_holiday_dows', implode(',', $validated['prescribed_holiday_dows'] ?? []));

        return back()->with('success', '勤怠設定を保存しました');
    }
}
