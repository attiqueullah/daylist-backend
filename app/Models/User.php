<?php

namespace App\Models;

use App\Models\Concerns\SyncableModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SyncableModel;

    protected $fillable = [
        'id', 'name', 'email', 'password', 'avatar_url', 'timezone', 'settings', 'email_verified_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    /** Settings with defaults filled in for keys the user never changed. */
    public function resolvedSettings(): array
    {
        return array_merge(config('daylist.default_settings'), $this->settings ?? []);
    }

    public function setting(string $key): mixed
    {
        return $this->resolvedSettings()[$key] ?? null;
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ListGroup::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ListMember::class);
    }

    public function defaultList(): ?TaskList
    {
        return TaskList::where('owner_id', $this->id)->where('is_default', true)->first();
    }
}
