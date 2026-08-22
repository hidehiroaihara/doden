<?php

namespace App\Models;

use App\Support\AdminPermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $guard = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'permissions'       => 'array',
        ];
    }

    /** スーパー管理者（全権限） */
    public function isSuperAdmin(): bool
    {
        return (int) $this->role === 1;
    }

    /** セクション・レベルの権限チェック */
    public function can($ability, $arguments = []): bool
    {
        return parent::can($ability, $arguments);
    }

    public function hasPermission(string $section, string $requiredLevel): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return AdminPermission::can(
            $this->permissions ?? AdminPermission::DEFAULTS,
            $section,
            $requiredLevel,
        );
    }

    /** permissions を正規化して返す（未設定キーをデフォルトで補完） */
    public function normalizedPermissions(): array
    {
        $stored = $this->permissions ?? [];
        return array_merge(AdminPermission::DEFAULTS, $stored);
    }
}
