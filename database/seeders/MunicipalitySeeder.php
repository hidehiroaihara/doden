<?php

namespace Database\Seeders;

use App\Models\ResidentTaxMunicipality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 全国市区町村マスタ（都道府県付き）をシードする。
 * データ元: database/data/municipalities.json（geolonia/japanese-addresses を市区町村レベルへ整形）。
 * 政令指定都市は区を市へ集約済み。指定番号（特別徴収義務者番号）は別途登録・更新する。
 */
class MunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/municipalities.json');
        if (! is_file($path)) {
            $this->command?->warn("municipalities.json が見つかりません: {$path}");

            return;
        }

        /** @var array<string, array<int, string>> $data */
        $data = json_decode((string) file_get_contents($path), true) ?: [];

        $now = now();
        $rows = [];
        foreach ($data as $prefecture => $names) {
            foreach ($names as $name) {
                $rows[] = [
                    'name' => $name,
                    'prefecture' => $prefecture,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 既存の name はユニーク制約があるため upsert で都道府県を補完する。
        foreach (array_chunk($rows, 500) as $chunk) {
            ResidentTaxMunicipality::upsert($chunk, ['name'], ['prefecture', 'updated_at']);
        }

        $this->command?->info('市区町村マスタを '.count($rows).' 件シードしました。');
    }
}
