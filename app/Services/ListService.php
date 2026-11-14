<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\ListMember;
use App\Models\ShareInvite;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Iso;
use Illuminate\Support\Facades\DB;

class ListService
{
    public function create(User $user, array $attributes, bool $isDefault = false): TaskList
    {
        return DB::transaction(function () use ($user, $attributes, $isDefault) {
            $list = TaskList::create(array_merge(
                array_intersect_key($attributes, array_flip([
                    'id', 'name', 'emoji', 'theme_color', 'theme_image', 'sort_mode', 'sort_direction', 'show_completed',
                ])),
                ['owner_id' => $user->id, 'is_default' => $isDefault],
            ));

            ListMember::create([
                'list_id' => $list->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'group_id' => $attributes['group_id'] ?? null,
                'sort_order' => $attributes['sort_order'] ?? $this->nextListPosition($user),
                'joined_at' => Iso::now(),
            ]);

            return $list;
        });
    }

    public function createDefaultList(User $user): TaskList
    {
        return $user->defaultList() ?? $this->create($user, ['name' => 'Tasks', 'sort_order' => 0], true);
    }

    public function nextListPosition(User $user): float
    {
        return (float) ListMember::where('user_id', $user->id)->max('sort_order') + 1;
    }

    /** Copies the list with its open tasks and their steps. The copy is private to the user. */
    public function duplicate(User $user, TaskList $source, ?string $name = null): TaskList
    {
        return DB::transaction(function () use ($user, $source, $name) {
            $copy = $this->create($user, [
                'name' => $name ?? $source->name.' copy',
                'emoji' => $source->emoji,
                'theme_color' => $source->theme_color,
                'theme_image' => $source->theme_image,
                'sort_mode' => $source->sort_mode,
                'sort_direction' => $source->sort_direction,
                'show_completed' => $source->show_completed,
                'group_id' => $source->membershipFor($user)?->group_id,
            ]);

            $tasks = $source->tasks()->where('is_completed', false)->with('steps')->get();
            foreach ($tasks as $task) {
                $new = Task::create([
                    'list_id' => $copy->id,
                    'created_by' => $user->id,
                    'title' => $task->title,
                    'notes' => $task->notes,
                    'is_important' => $task->is_important,
                    'due_date' => $task->due_date,
                    'reminder_at' => $task->reminder_at,
                    'recurrence' => $task->recurrence,
                    'sort_order' => $task->sort_order,
                ]);
                foreach ($task->steps as $step) {
                    Step::create([
                        'task_id' => $new->id,
                        'title' => $step->title,
                        'is_completed' => $step->is_completed,
                        'sort_order' => $step->sort_order,
                    ]);
                }
            }

            return $copy;
        });
    }

    /** Soft-deletes the list and everything in it, leaving tombstones for sync. */
    public function delete(TaskList $list): void
    {
        DB::transaction(function () use ($list) {
            $now = Iso::db();
            $taskIds = Task::where('list_id', $list->id)->pluck('id');

            foreach ($taskIds->chunk(500) as $chunk) {
                Step::whereIn('task_id', $chunk)->update(['deleted_at' => $now, 'updated_at' => $now]);
                Attachment::whereIn('task_id', $chunk)->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            Task::where('list_id', $list->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            ListMember::where('list_id', $list->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            ShareInvite::where('list_id', $list->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);

            $list->delete();
        });
    }
}
