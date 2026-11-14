<?php

namespace App\Models;

use App\Models\Concerns\SyncableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes, SyncableModel;

    protected $fillable = [
        'id', 'list_id', 'created_by', 'title', 'notes', 'is_completed', 'completed_at', 'completed_by',
        'is_important', 'due_date', 'reminder_at', 'recurrence', 'next_task_id', 'my_day_date',
        'assignee_id', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'is_important' => 'boolean',
            'completed_at' => 'datetime',
            // due_date and my_day_date stay plain "Y-m-d" strings: they are
            // calendar days, not instants, so no timezone conversion applies.
            'reminder_at' => 'datetime',
            'sort_order' => 'float',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(Step::class)->orderBy('sort_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
