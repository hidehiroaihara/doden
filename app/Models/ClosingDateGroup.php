<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClosingDateGroup extends Model
{
    protected $fillable = [
        'name',
        'closing_day',
        'payment_day',
        'payment_month_offset',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'closing_day' => 'integer',
            'payment_day' => 'integer',
            'payment_month_offset' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function employeePayrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
    }
}
