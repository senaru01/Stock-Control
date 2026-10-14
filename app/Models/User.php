<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\AccessControl;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'permissions', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === AccessControl::ROLE_SUPERADMIN;
    }

    /**
     * Whether the user may do "<menu>.<ability>"; super admins may do everything.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->is_active && ($this->isSuperAdmin() || in_array($permission, $this->permissions ?? [], true));
    }

    public function roleLabel(): string
    {
        return AccessControl::ROLES[$this->role] ?? $this->role;
    }

    /**
     * The first page the user may open, or null when they may open none.
     */
    public function homeRoute(): ?string
    {
        foreach (AccessControl::MENU_ROUTES as $menu => $route) {
            if ($this->hasPermission("{$menu}.".AccessControl::VIEW)) {
                return $route;
            }
        }

        return null;
    }
}
