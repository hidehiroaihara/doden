<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDependent extends Model
{
    protected $fillable = [
        'user_id',
        'last_name',
        'first_name',
        'last_name_kana',
        'first_name_kana',
        'birth_date',
        'relationship',
        'my_number',
        'lives_together',
        'is_income_tax_dependent',
        'dependent_type',
        'is_same_livelihood_spouse',
        'disability_type',
        'is_health_insurance_dependent',
        'annual_income',
        'sort_order',
    ];

    protected $hidden = [
        'my_number',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'my_number' => 'encrypted',
            'lives_together' => 'boolean',
            'is_income_tax_dependent' => 'boolean',
            'is_same_livelihood_spouse' => 'boolean',
            'is_health_insurance_dependent' => 'boolean',
            'annual_income' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->last_name ?? '').' '.($this->first_name ?? ''));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
