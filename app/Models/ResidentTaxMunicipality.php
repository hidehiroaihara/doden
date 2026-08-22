<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResidentTaxMunicipality extends Model
{
    protected $fillable = [
        'name',
        'designation_number',
    ];

    /**
     * 従業員が指定した市区町村名がマスタに無ければ追加する（同期）。
     */
    public static function sync(?string $name): void
    {
        $name = trim((string) $name);
        if ($name === '') {
            return;
        }

        static::firstOrCreate(['name' => $name]);
    }
}
