<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResidentTaxMunicipality extends Model
{
    protected $fillable = [
        'name',
        'prefecture',
        'designation_number',
    ];

    /**
     * 従業員が指定した市区町村名がマスタに無ければ追加する（同期）。
     */
    public static function sync(?string $name, ?string $prefecture = null): void
    {
        $name = trim((string) $name);
        if ($name === '') {
            return;
        }

        $municipality = static::firstOrCreate(['name' => $name]);

        $prefecture = trim((string) $prefecture);
        if ($prefecture !== '' && $municipality->prefecture !== $prefecture) {
            $municipality->update(['prefecture' => $prefecture]);
        }
    }

    /**
     * 都道府県ごとの市区町村名リストを返す（フロント連動プルダウン用）。
     *
     * @return array<string, array<int, string>>
     */
    public static function optionsByPrefecture(): array
    {
        return static::query()
            ->whereNotNull('prefecture')
            ->orderBy('id')
            ->get(['name', 'prefecture'])
            ->groupBy('prefecture')
            ->map(fn ($rows) => $rows->pluck('name')->values()->all())
            ->all();
    }
}
