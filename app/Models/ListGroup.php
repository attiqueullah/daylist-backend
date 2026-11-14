<?php

namespace App\Models;

use App\Models\Concerns\SyncableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ListGroup extends Model
{
    use SoftDeletes, SyncableModel;

    protected $fillable = ['id', 'user_id', 'name', 'sort_order', 'is_collapsed'];

    protected function casts(): array
    {
        return ['sort_order' => 'float', 'is_collapsed' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
