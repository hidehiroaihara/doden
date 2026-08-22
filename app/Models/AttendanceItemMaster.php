<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceItemMaster extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'is_active',
        'unit_format',
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

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
