<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'role', 'google_id', 'google_avatar', 'email_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    protected $appends = ['avatar'];

    public function hasGoogleLink(): bool
    {
        return filled($this->google_id);
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->google_avatar);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * @return HasMany<ProductReport, self>
     */
    public function productReports(): HasMany
    {
        return $this->hasMany(ProductReport::class);
    }

    /**
     * @return HasMany<SavedCart, self>
     */
    public function savedCarts(): HasMany
    {
        return $this->hasMany(SavedCart::class);
    }

    /**
     * @return HasMany<FamilySubscription, self>
     */
    public function familySubscriptions(): HasMany
    {
        return $this->hasMany(FamilySubscription::class);
    }
}
