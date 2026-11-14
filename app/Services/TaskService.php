<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Iso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public const WRITABLE = [
        'title', 'notes', 'is_important', 'due_date', 'reminder_at', 'recurrence', 'my_day_date', 'assignee_id', 'sort_order',
    ];

    public function __construct(private RecurrenceService $recurrence) {}

    public function create(User $user, TaskList $list, array $data, Carbon $today): Task
    {
        $attributes = array_intersect_key($data, array_flip(array_merge(['id'], self::WRITABLE)));
        $this->checkAssignee($list, $attributes['assignee_id'] ?? null);

        if (! empty($attributes['recurrence'])) {
            $attributes['recurrence'] = $this->recurrence->normalise($attributes['recurrence']);
            $attributes['due_date'] ??= $today->format('Y-m-d');
        }

        if (! array_key_exists('sort_order', $attributes) || $attributes['sort_order'] === null) {
            $attributes['sort_order'] = $user->setting('add_to_top')
                ? (float) Task::where('list_id', $list->id)->min('sort_order') - 1
                : (float) Task::where('list_id', $list->id)->max('sort_order') + 1;
        }

        return Task::create(array_merge($attributes, ['list_id' => $list->id, 'created_by' => $user->id]));
    }

    /**
     * Applies a partial update. Returns [task, nextTask|null]; nextTask is the
     * new occurrence when a repeating task was just completed.
     */
    public function update(User $user, Task $task, array $data, Carbon $today): array
    {
        return DB::transaction(function () use ($user, $task, $data, $today) {
            $attributes = array_intersect_key($data, array_flip(self::WRITABLE));

            if (array_key_exists('list_id', $data) && $data['list_id'] !== $task->list_id) {
                $target = TaskList::findOrFail($data['list_id']);
                if (! $target->hasMember($user)) {
                    throw ValidationException::withMessages(['list_id' => 'You are not a member of that list.']);
                }
                $task->list_id = $target->id;
                // An assignee who is not on the new list loses the assignment.
                if ($task->assignee_id && ! array_key_exists('assignee_id', $attributes) && ! $target->hasMember($task->assignee_id)) {
                    $task->assignee_id = null;
                }
            }

            if (array_key_exists('assignee_id', $attributes)) {
                $this->checkAssignee($task->list()->first(), $attributes['assignee_id']);
            }

            if (array_key_exists('recurrence', $attributes) && $attributes['recurrence'] !== null) {
                $attributes['recurrence'] = $this->recurrence->normalise($attributes['recurrence']);
                if (! array_key_exists('due_date', $attributes) && $task->due_date === null) {
                    $attributes['due_date'] = $today->format('Y-m-d');
                }
            }

            $task->fill($attributes);

            $next = null;
            if (array_key_exists('is_completed', $data)) {
                $completing = (bool) $data['is_completed'];
                if ($completing && ! $task->is_completed) {
                    $task->is_completed = true;
                    $task->completed_at = Iso::now();
                    $task->completed_by = $user->id;
                    $task->save();
                    $next = $this->recurrence->spawnNext($task->fresh(), $today);
                } elseif (! $completing && $task->is_completed) {
                    $task->is_completed = false;
                    $task->completed_at = null;
                    $task->completed_by = null;
                }
            }

            $task->save();

            return [$task->fresh(), $next];
        });
    }

    public function delete(Task $task): void
    {
        DB::transaction(function () use ($task) {
            $now = Iso::db();
            Step::where('task_id', $task->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            Attachment::where('task_id', $task->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $task->delete();
        });
    }

    private function checkAssignee(?TaskList $list, ?string $assigneeId): void
    {
        if ($assigneeId !== null && ($list === null || ! $list->hasMember($assigneeId))) {
            throw ValidationException::withMessages(['assignee_id' => 'The assignee must be a member of the list.']);
        }
    }
}
