<?php

namespace App\Models;

use App\Models\Concerns\SyncableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskList extends Model
{
    use SoftDeletes, SyncableModel;

    public const SORT_MODES = ['manual', 'importance', 'due_date', 'alphabetical', 'created_at', 'my_day'];

    protected $fillable = [
        'id', 'owner_id', 'name', 'emoji', 'theme_color', 'theme_image', 'sort_mode', 'sort_direction',
        'show_completed', 'is_default', 'is_shared', 'sharing_limited',
    ];

    protected function casts(): array
    {
        return [
            'show_completed' => 'boolean',
            'is_default' => 'boolean',
            'is_shared' => 'boolean',
            'sharing_limited' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ListMember::class, 'list_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'list_id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(ShareInvite::class, 'list_id');
    }

    public function membershipFor(User|string $user): ?ListMember
    {
        $userId = $user instanceof User ? $user->id : $user;

        return ListMember::where('list_id', $this->id)->where('user_id', $userId)->first();
    }

    public function hasMember(User|string $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }
}
