<?php

namespace App\Models;

use App\Models\Concerns\SyncableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person on a list. The owner has a row too. Group and sort order are
 * stored here because each user arranges their own Home screen.
 */
class ListMember extends Model
{
    use SoftDeletes, SyncableModel;

    protected $fillable = [
        'id', 'list_id', 'user_id', 'role', 'group_id', 'sort_order', 'notifications_muted', 'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'float',
            'notifications_muted' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
