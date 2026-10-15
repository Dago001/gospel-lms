<?php

namespace App\Models;

use App\Notifications\QueuedVerifyEmail;
use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('assigned_by');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function twoFactor(): HasOne
    {
        return $this->hasOne(TwoFactorAuthentication::class);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function roleSlugs(): array
    {
        return $this->roles->pluck('slug')->all();
    }

    public function hasRole(string ...$slugs): bool
    {
        return count(array_intersect($slugs, $this->roleSlugs())) > 0;
    }

    public function permissionSlugs(): array
    {
        return $this->permissionCache ??= Permission::query()
            ->whereHas('roles.users', fn ($q) => $q->where('users.id', $this->id))
            ->pluck('slug')->all();
    }

    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissionSlugs(), true);
    }

    public function flushAuthorizationCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }

    public function isSuspended(): bool
    {
        return $this->status !== 'active';   // suspended or anonymised accounts can never act
    }

    /** Privileged users are required to hold a confirmed second factor. */
    public function requiresMfa(): bool
    {
        return $this->hasRole(...Roles::PRIVILEGED);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFactor()->whereNotNull('confirmed_at')->exists();
    }
}
