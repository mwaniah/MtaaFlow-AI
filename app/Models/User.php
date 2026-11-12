<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role
 * @property string $preferred_currency
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'preferred_currency', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // One user can have many customers
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    // One user can create many invoices
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Check whether a user is an administrator
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }
}
