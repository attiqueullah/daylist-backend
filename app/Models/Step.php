<?php

namespace App\Models;

use App\Models\Concerns\SyncableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Step extends Model
{
    use SoftDeletes, SyncableModel;

    protected $fillable = ['id', 'task_id', 'title', 'is_completed', 'sort_order'];

    protected function casts(): array
    {
        return ['is_completed' => 'boolean', 'sort_order' => 'float'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
