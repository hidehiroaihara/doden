<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'last_name',
        'first_name',
        'last_name_kana',
        'first_name_kana',
        'gender',
        'email',
        'password',
        'is_active',
        'joined_at',
        'chatwork_room_id',
        'role',
        'department_id',
        'customer_no',
        'resume_path',
        'identification_document_path',
        'phone',
        'postal_code',
        'prefecture',
        'city',
        'street',
        'building',
        'address_kana',
        'address',
        'my_number',
        'birth_date',
        'emergency_contact_name',
        'emergency_contact_phone',
        'break_minutes',
        'retirement_date',
        'retirement_type',
        'retirement_reason',
        'employee_note',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'my_number',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'birth_date' => 'date',
            'joined_at' => 'date',
            'retirement_date' => 'date',
            'my_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // 姓・名から表示名(name)を自動合成し、name 参照箇所の後方互換を保つ。
        static::saving(function (User $user) {
            $last = trim((string) ($user->last_name ?? ''));
            $first = trim((string) ($user->first_name ?? ''));
            $composed = trim($last.' '.$first);
            if ($composed !== '') {
                $user->name = $composed;
            }
        });
    }

    /** 表示用のフルネーム（姓 名）。分割が無ければ name にフォールバック。 */
    public function getFullNameAttribute(): string
    {
        $composed = trim(((string) $this->last_name).' '.((string) $this->first_name));
        return $composed !== '' ? $composed : (string) $this->name;
    }

    /**
     * 雇用ステータスを返す。
     * - retired  : is_active=false
     * - pre_join : is_active=true かつ joined_at が未来日
     * - active   : それ以外
     */
    public function getEmploymentStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'retired';
        }
        if ($this->joined_at && $this->joined_at->isFuture()) {
            return 'pre_join';
        }
        return 'active';
    }

    /** 打刻画面に表示すべき在籍中かどうか */
    public function isPunchable(): bool
    {
        return $this->employment_status === 'active';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function employeePayroll(): HasOne
    {
        return $this->hasOne(EmployeePayroll::class);
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(EmployeeDependent::class)->orderBy('sort_order')->orderBy('id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class)->orderByDesc('start_date');
    }

    public function payItemValues(): HasMany
    {
        return $this->hasMany(EmployeePayItemValue::class);
    }

    public function commuteRoutes(): HasMany
    {
        return $this->hasMany(EmployeeCommuteRoute::class)->orderBy('sort_order')->orderBy('id');
    }

    public function residentTaxes(): HasMany
    {
        return $this->hasMany(EmployeeResidentTax::class)->orderBy('fiscal_year')->orderBy('month');
    }

    public function standardRewards(): HasMany
    {
        return $this->hasMany(EmployeeStandardReward::class)->orderBy('applied_from');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(UserStatusHistory::class)->orderByDesc('changed_at');
    }

    /**
     * 従業員番号（employee_payrolls.employee_no）の自然順で並べる。
     * "2" < "10"（数値）や "E001" < "E002"（ゼロ埋め）の両方に対応し、未設定は末尾。
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     */
    public function scopeOrderByEmployeeNo($query)
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('users.*');
        }

        return $query
            ->leftJoin('employee_payrolls as ep_sort', 'ep_sort.user_id', '=', 'users.id')
            ->orderByRaw("(ep_sort.employee_no IS NULL OR ep_sort.employee_no = '')")
            ->orderByRaw('LENGTH(ep_sort.employee_no)')
            ->orderBy('ep_sort.employee_no');
    }
}
