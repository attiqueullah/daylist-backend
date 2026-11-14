<?php

namespace Tests\Feature;

use App\Models\Step;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_task_with_all_fields(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);

        $this->postJson("/api/v1/lists/{$list->id}/tasks", [
            'title' => 'Buy milk',
            'notes' => '2 litres',
            'is_important' => true,
            'due_date' => '2026-11-20',
            'reminder_at' => '2026-11-20T04:00:00Z',
            'my_day_date' => '2026-11-14',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Buy milk')
            ->assertJsonPath('data.list_id', $list->id)
            ->assertJsonPath('data.is_important', true)
            ->assertJsonPath('data.due_date', '2026-11-20')
            ->assertJsonPath('data.reminder_at', '2026-11-20T04:00:00.000000Z')
            ->assertJsonPath('data.my_day_date', '2026-11-14')
            ->assertJsonPath('data.created_by', $user->id);
    }

    public function test_validation(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);

        $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'x', 'due_date' => '20-11-2026'])->assertJsonValidationErrors('due_date');
        $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'x', 'recurrence' => 'FREQ=HOURLY'])->assertJsonValidationErrors('recurrence');
        $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'x', 'recurrence' => 'FREQ=DAILY;COUNT=3'])->assertJsonValidationErrors('recurrence');
        $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'x', 'assignee_id' => $this->makeUser()->id])->assertJsonValidationErrors('assignee_id');
    }

    public function test_new_tasks_go_to_bottom_or_top_by_setting(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);

        $first = $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'First'])->json('data.sort_order');
        $second = $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'Second'])->json('data.sort_order');
        $this->assertGreaterThan($first, $second);

        $user->update(['settings' => ['add_to_top' => true]]);
        $top = $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'Top'])->json('data.sort_order');
        $this->assertLessThan($first, $top);
    }

    public function test_quick_add_from_smart_list_goes_to_default_list(): void
    {
        $user = $this->signIn();

        $this->postJson('/api/v1/tasks?today=2026-11-14', ['title' => 'Call mum', 'my_day_date' => '2026-11-14'])
            ->assertCreated()
            ->assertJsonPath('data.list_id', $user->defaultList()->id);

        $list = $this->makeList($user);
        $this->postJson('/api/v1/tasks', ['title' => 'In list', 'list_id' => $list->id])->assertJsonPath('data.list_id', $list->id);

        $foreign = $this->makeList($this->makeUser());
        $this->postJson('/api/v1/tasks', ['title' => 'Nope', 'list_id' => $foreign->id])->assertNotFound();
    }

    public function test_replayed_create_returns_existing_task(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);
        $id = (string) Str::uuid();

        $this->postJson("/api/v1/lists/{$list->id}/tasks", ['id' => $id, 'title' => 'Offline'])->assertCreated();
        $this->postJson("/api/v1/lists/{$list->id}/tasks", ['id' => $id, 'title' => 'Offline'])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Task::count());
    }

    public function test_show_includes_steps_and_attachments(): void
    {
        $user = $this->signIn();
        $task = $this->makeTask($this->makeList($user));
        Step::create(['task_id' => $task->id, 'title' => 'Step 1']);

        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.steps.0.title', 'Step 1')
            ->assertJsonPath('data.attachments', []);
    }

    public function test_update_clear_fields_and_move(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);
        $other = $this->makeList($user, ['name' => 'Other']);
        $task = $this->makeTask($list, ['due_date' => '2026-11-20', 'notes' => 'Old']);

        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Renamed', 'due_date' => null, 'notes' => null, 'list_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.due_date', null)
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.list_id', $other->id);

        $foreign = $this->makeList($this->makeUser());
        $this->patchJson("/api/v1/tasks/{$task->id}", ['list_id' => $foreign->id])->assertUnprocessable();
    }

    public function test_complete_and_uncomplete(): void
    {
        $user = $this->signIn();
        $task = $this->makeTask($this->makeList($user));

        $this->patchJson("/api/v1/tasks/{$task->id}", ['is_completed' => true])
            ->assertOk()
            ->assertJsonPath('data.is_completed', true)
            ->assertJsonPath('data.completed_by', $user->id)
            ->assertJsonMissingPath('next_task');
        $this->assertNotNull($task->fresh()->completed_at);

        $this->patchJson("/api/v1/tasks/{$task->id}", ['is_completed' => false])
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_completing_a_repeating_task_creates_the_next_one_once(): void
    {
        $user = $this->signIn();
        $task = $this->makeTask($this->makeList($user), [
            'title' => 'Gym',
            'due_date' => '2026-11-16', // Monday
            'reminder_at' => '2026-11-16 13:00:00',
            'recurrence' => 'FREQ=WEEKLY;BYDAY=MO,WE',
            'is_important' => true,
        ]);
        Step::create(['task_id' => $task->id, 'title' => 'Warm up', 'is_completed' => true]);

        $response = $this->patchJson("/api/v1/tasks/{$task->id}", ['is_completed' => true])->assertOk();
        $response->assertJsonPath('next_task.title', 'Gym')
            ->assertJsonPath('next_task.due_date', '2026-11-18')
            ->assertJsonPath('next_task.reminder_at', '2026-11-18T13:00:00.000000Z')
            ->assertJsonPath('next_task.is_important', true)
            ->assertJsonPath('next_task.is_completed', false)
            ->assertJsonPath('next_task.steps.0.title', 'Warm up')
            ->assertJsonPath('next_task.steps.0.is_completed', false)
            ->assertJsonPath('data.next_task_id', $response->json('next_task.id'));

        // Un-complete and complete again: no second copy.
        $this->patchJson("/api/v1/tasks/{$task->id}", ['is_completed' => false]);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['is_completed' => true])->assertJsonMissingPath('next_task');
        $this->assertSame(2, Task::count());
    }

    public function test_repeat_without_due_date_starts_today(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);

        $this->postJson("/api/v1/lists/{$list->id}/tasks?today=2026-11-14", ['title' => 'Water plants', 'recurrence' => 'RRULE:FREQ=DAILY'])
            ->assertCreated()
            ->assertJsonPath('data.due_date', '2026-11-14')
            ->assertJsonPath('data.recurrence', 'FREQ=DAILY');
    }

    public function test_delete_task_soft_deletes_steps(): void
    {
        $user = $this->signIn();
        $task = $this->makeTask($this->makeList($user));
        $step = Step::create(['task_id' => $task->id, 'title' => 'x']);

        $this->deleteJson("/api/v1/tasks/{$task->id}")->assertNoContent();
        $this->assertSoftDeleted($task);
        $this->assertSoftDeleted($step);
        $this->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
    }

    public function test_list_tasks_follow_sort_mode_and_completed_toggle(): void
    {
        $user = $this->signIn();
        $user->update(['settings' => ['starred_to_top' => false]]);
        $list = $this->makeList($user, ['sort_mode' => 'alphabetical']);
        $this->makeTask($list, ['title' => 'Cheese', 'sort_order' => 1]);
        $this->makeTask($list, ['title' => 'apples', 'sort_order' => 2]);
        $this->makeTask($list, ['title' => 'Bread', 'sort_order' => 3]);
        $this->makeTask($list, ['title' => 'Done', 'is_completed' => true]);

        $titles = collect($this->getJson("/api/v1/lists/{$list->id}/tasks")->json('data'))->pluck('title')->all();
        $this->assertSame(['apples', 'Bread', 'Cheese', 'Done'], $titles);

        $this->getJson("/api/v1/lists/{$list->id}/tasks?include_completed=0")->assertJsonCount(3, 'data');

        $list->update(['sort_mode' => 'manual']);
        $titles = collect($this->getJson("/api/v1/lists/{$list->id}/tasks?include_completed=0")->json('data'))->pluck('title')->all();
        $this->assertSame(['Cheese', 'apples', 'Bread'], $titles);
    }

    public function test_steps_crud_and_promote(): void
    {
        $user = $this->signIn();
        $task = $this->makeTask($this->makeList($user));

        $stepId = $this->postJson("/api/v1/tasks/{$task->id}/steps", ['title' => 'Find recipe'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/tasks/{$task->id}/steps", ['title' => 'Buy flour'])->assertCreated();

        $this->patchJson("/api/v1/steps/{$stepId}", ['is_completed' => true])->assertOk()->assertJsonPath('data.is_completed', true);
        $this->getJson("/api/v1/tasks/{$task->id}/steps")->assertJsonCount(2, 'data')->assertJsonPath('data.0.title', 'Find recipe');

        $this->postJson("/api/v1/steps/{$stepId}/promote")
            ->assertCreated()
            ->assertJsonPath('data.title', 'Find recipe')
            ->assertJsonPath('data.list_id', $task->list_id);
        $this->getJson("/api/v1/tasks/{$task->id}/steps")->assertJsonCount(1, 'data');

        $other = $this->makeTask($this->makeList($this->makeUser()));
        $foreignStep = Step::create(['task_id' => $other->id, 'title' => 'Hidden']);
        $this->patchJson("/api/v1/steps/{$foreignStep->id}", ['title' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/tasks/{$other->id}/steps", ['title' => 'x'])->assertNotFound();
    }
}
