<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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
 * Authenticated application user (Fortify + passkeys + 2FA).
 *
 * Display name is composed from first_name / last_name like externa-core and excubia-app.
 *
 * @property int $id
 * @property string $first_name
 * @property string|null $last_name
 * @property-read string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string $locale
 * @property string $timezone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'first_name',
    'last_name',
    'email',
    'password',
    'locale',
    'timezone',
    'is_active',
    'last_login_at',
    'last_login_ip',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The full name travels with the user to the pages (avatar, menus).
     *
     * @var list<string>
     */
    protected $appends = ['name'];

    /**
     * Column defaults a freshly created user does not read back from the
     * database (same values as the users migration): strict models throw on a
     * missing attribute.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'last_name' => null,
        'locale' => 'en',
        'timezone' => 'UTC',
        'last_login_at' => null,
        'last_login_ip' => null,
        'email_verified_at' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Full display name composed from first and last name.
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * @return HasMany<Principal, $this>
     */
    public function principals(): HasMany
    {
        return $this->hasMany(Principal::class);
    }
}
