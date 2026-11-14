<?php

namespace Tests\Feature;

use App\Models\ListMember;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListAndGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_list_with_theme_and_group(): void
    {
        $this->signIn();
        $group = $this->postJson('/api/v1/groups', ['name' => 'Home'])->assertCreated()->json('data');

        $this->postJson('/api/v1/lists', [
            'name' => 'Groceries', 'emoji' => '🛒', 'theme_color' => 'moss', 'group_id' => $group['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Groceries')
            ->assertJsonPath('data.emoji', '🛒')
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.group_id', $group['id'])
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.open_task_count', 0);
    }

    public function test_index_lists_default_first_then_by_position(): void
    {
        $user = $this->signIn();
        $this->makeList($user, ['name' => 'B', 'sort_order' => 5]);
        $this->makeList($user, ['name' => 'A', 'sort_order' => 2]);

        $names = collect($this->getJson('/api/v1/lists')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Tasks', 'A', 'B'], $names);
    }

    public function test_create_with_client_id_is_idempotent(): void
    {
        $user = $this->signIn();
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/lists', ['id' => $id, 'name' => 'Offline list'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/lists', ['id' => $id, 'name' => 'Offline list'])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(2, TaskList::where('owner_id', $user->id)->count());

        // Someone else's id cannot be claimed.
        $other = $this->makeList($this->makeUser());
        $this->postJson('/api/v1/lists', ['id' => $other->id, 'name' => 'Steal'])->assertStatus(409);
    }

    public function test_cannot_see_or_change_someone_elses_list(): void
    {
        $this->signIn();
        $list = $this->makeList($this->makeUser());

        $this->getJson("/api/v1/lists/{$list->id}")->assertNotFound();
        $this->patchJson("/api/v1/lists/{$list->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/lists/{$list->id}")->assertNotFound();
        $this->getJson('/api/v1/lists/not-a-uuid')->assertNotFound();
    }

    public function test_update_list_and_own_placement(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);
        $group = $this->postJson('/api/v1/groups', ['name' => 'Work'])->json('data');

        $this->patchJson("/api/v1/lists/{$list->id}", [
            'name' => 'Shopping', 'sort_mode' => 'due_date', 'show_completed' => false, 'group_id' => $group['id'], 'sort_order' => 3.5,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Shopping')
            ->assertJsonPath('data.sort_mode', 'due_date')
            ->assertJsonPath('data.show_completed', false)
            ->assertJsonPath('data.group_id', $group['id'])
            ->assertJsonPath('data.sort_order', 3.5);

        $this->patchJson("/api/v1/lists/{$list->id}", ['sort_mode' => 'random'])->assertUnprocessable();

        // A group of another user is rejected.
        $foreignGroup = $this->makeUser()->groups()->create(['name' => 'Theirs']);
        $this->patchJson("/api/v1/lists/{$list->id}", ['group_id' => $foreignGroup->id])->assertUnprocessable();
    }

    public function test_default_list_cannot_be_renamed_or_deleted(): void
    {
        $user = $this->signIn();
        $default = $user->defaultList();

        $this->patchJson("/api/v1/lists/{$default->id}", ['name' => 'Inbox'])->assertUnprocessable();
        $this->patchJson("/api/v1/lists/{$default->id}", ['theme_color' => 'coral'])->assertOk();
        $this->deleteJson("/api/v1/lists/{$default->id}")->assertUnprocessable();
    }

    public function test_delete_list_soft_deletes_everything_inside(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user);
        $task = $this->makeTask($list);
        $step = Step::create(['task_id' => $task->id, 'title' => 'Organic']);

        $this->deleteJson("/api/v1/lists/{$list->id}")->assertNoContent();

        $this->assertSoftDeleted($list);
        $this->assertSoftDeleted($task);
        $this->assertSoftDeleted($step);
        $this->assertTrue(ListMember::withTrashed()->where('list_id', $list->id)->first()->trashed());
        $this->getJson("/api/v1/lists/{$list->id}")->assertNotFound();
    }

    public function test_member_cannot_delete_shared_list(): void
    {
        $owner = $this->makeUser();
        $list = $this->makeList($owner);
        $member = $this->signIn();
        $this->share($list, $member);

        $this->deleteJson("/api/v1/lists/{$list->id}")->assertForbidden();
    }

    public function test_duplicate_copies_open_tasks_and_steps(): void
    {
        $user = $this->signIn();
        $list = $this->makeList($user, ['theme_color' => 'lagoon']);
        $open = $this->makeTask($list, ['title' => 'Bread']);
        Step::create(['task_id' => $open->id, 'title' => 'Whole wheat']);
        $this->makeTask($list, ['title' => 'Done thing', 'is_completed' => true]);

        $copyId = $this->postJson("/api/v1/lists/{$list->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Groceries copy')
            ->assertJsonPath('data.theme_color', 'lagoon')
            ->json('data.id');

        $copied = Task::where('list_id', $copyId)->get();
        $this->assertSame(['Bread'], $copied->pluck('title')->all());
        $this->assertSame('Whole wheat', $copied->first()->steps->first()->title);
    }

    public function test_groups_crud_and_delete_ungroups_lists(): void
    {
        $user = $this->signIn();
        $groupId = $this->postJson('/api/v1/groups', ['name' => 'Home'])->json('data.id');
        $list = $this->makeList($user, ['group_id' => $groupId]);

        $this->patchJson("/api/v1/groups/{$groupId}", ['is_collapsed' => true, 'name' => 'House'])
            ->assertOk()->assertJsonPath('data.is_collapsed', true)->assertJsonPath('data.name', 'House');
        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/groups/{$groupId}")->assertNoContent();

        $this->assertNull($list->membershipFor($user)->group_id);
        $this->getJson('/api/v1/groups')->assertJsonCount(0, 'data');

        $theirs = $this->makeUser()->groups()->create(['name' => 'Private']);
        $this->patchJson("/api/v1/groups/{$theirs->id}", ['name' => 'x'])->assertNotFound();
    }
}
