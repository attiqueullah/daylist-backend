<?php

namespace Tests;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\ListService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function makeUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(ListService::class)->createDefaultList($user);

        return $user;
    }

    protected function signIn(?User $user = null): User
    {
        $user ??= $this->makeUser();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeList(User $owner, array $attributes = []): TaskList
    {
        return app(ListService::class)->create($owner, array_merge(['name' => 'Groceries'], $attributes));
    }

    protected function makeTask(TaskList $list, array $attributes = []): Task
    {
        $task = Task::create(array_merge([
            'list_id' => $list->id,
            'created_by' => $list->owner_id,
            'title' => 'Milk',
            'sort_order' => 1,
        ], $attributes));

        if (isset($attributes['created_at'])) {
            $task->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }

        return $task;
    }

    /** Adds $user to $list as a member, the way accepting an invite does. */
    protected function share(TaskList $list, User $user): void
    {
        $list->members()->create([
            'user_id' => $user->id,
            'role' => 'member',
            'sort_order' => 10,
            'joined_at' => now(),
        ]);
        $list->update(['is_shared' => true]);
    }
}
