<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeductionItemMaster extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'is_active',
        'calc_method',
        'calc_description',
        'show_zero',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_zero' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
