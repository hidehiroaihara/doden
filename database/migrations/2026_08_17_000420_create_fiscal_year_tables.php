<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 年度設定(se15)。年度ごとに休日設定・独自休日・1日の所定労働時間・所定労働(月平均)を保持する。
 * 既存のグローバル設定（休日曜日・所定労働）を現行年度へバックフィルする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique()->comment('対象年（例:2026）');
            $table->string('name')->nullable()->comment('給与月度の名称（任意）');
            $table->unsignedInteger('work_hours_per_day_minutes')->nullable()->comment('1日の所定労働時間(分)。未設定は休日設定から自動算出');
            $table->decimal('monthly_avg_work_days', 5, 1)->nullable()->comment('所定労働日数(月平均)。未設定は自動算出');
            $table->decimal('monthly_avg_work_hours', 6, 1)->nullable()->comment('所定労働時間(月平均)。未設定は自動算出');
            $table->timestamps();
        });

        // 休日設定: 曜日(0=日〜6=土) + 祝日(dow=7) ごとの区分
        Schema::create('fiscal_year_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('dow')->comment('0=日,1=月,...,6=土,7=祝日');
            $table->string('type')->default('weekday')->comment('weekday=平日 / prescribed=所定休日 / legal=法定休日');
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'dow']);
        });

        // 独自休日設定
        Schema::create('fiscal_year_custom_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('label')->nullable();
            $table->timestamps();
            $table->index(['fiscal_year_id', 'date']);
        });

        $this->backfillCurrentYear();
    }

    /** 既存のグローバル設定から現行年度(今年)の年度データを生成する。 */
    private function backfillCurrentYear(): void
    {
        $year = (int) date('Y');
        $fyId = DB::table('fiscal_years')->insertGetId([
            'year' => $year,
            'work_hours_per_day_minutes' => $this->settingInt('work_hours_per_day', 480),
            'monthly_avg_work_days' => $this->settingFloat('monthly_avg_work_days', 21),
            'monthly_avg_work_hours' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dowMap = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $legal = $this->splitDows($this->settingString('legal_holiday_dows', 'sunday'));
        $prescribed = $this->splitDows($this->settingString('prescribed_holiday_dows', 'saturday'));

        $rows = [];
        for ($dow = 0; $dow <= 7; $dow++) {
            $type = 'weekday';
            $name = array_search($dow, $dowMap, true);
            if ($name !== false && in_array($name, $legal, true)) {
                $type = 'legal';
            } elseif ($name !== false && in_array($name, $prescribed, true)) {
                $type = 'prescribed';
            }
            // 祝日(dow=7)は既定で平日扱い（MF既定に合わせる）
            $rows[] = [
                'fiscal_year_id' => $fyId,
                'dow' => $dow,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('fiscal_year_holidays')->insert($rows);
    }

    /** @return array<int, string> */
    private function splitDows(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', $value),
        )));
    }

    private function settingString(string $key, ?string $default): ?string
    {
        if (! Schema::hasTable('settings')) {
            return $default;
        }
        $v = DB::table('settings')->where('key', $key)->value('value');

        return $v ?? $default;
    }

    private function settingInt(string $key, int $default): int
    {
        $v = $this->settingString($key, (string) $default);

        return $v !== null && $v !== '' ? (int) $v : $default;
    }

    private function settingFloat(string $key, float $default): float
    {
        $v = $this->settingString($key, (string) $default);

        return $v !== null && $v !== '' ? (float) $v : $default;
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_year_custom_holidays');
        Schema::dropIfExists('fiscal_year_holidays');
        Schema::dropIfExists('fiscal_years');
    }
};
