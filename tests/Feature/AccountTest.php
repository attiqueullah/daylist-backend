<?php

namespace Tests\Feature;

use App\Models\ListMember;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('database', 'ok');
    }

    public function test_me_returns_settings_with_defaults(): void
    {
        $user = $this->signIn();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.settings.add_to_top', false)
            ->assertJsonPath('data.has_password', true);
    }

    public function test_update_merges_settings_and_ignores_unknown_keys(): void
    {
        $this->signIn();

        $this->patchJson('/api/v1/me', ['settings' => ['add_to_top' => true]])->assertOk();
        $this->patchJson('/api/v1/me', [
            'name' => 'New Name',
            'timezone' => 'Asia/Karachi',
            'settings' => ['week_start' => 'sunday', 'not_a_setting' => 1],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.settings.add_to_top', true)
            ->assertJsonPath('data.settings.week_start', 'sunday')
            ->assertJsonMissingPath('data.settings.not_a_setting');

        $this->patchJson('/api/v1/me', ['timezone' => 'Mars/Base'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }

    public function test_deleting_account_removes_data_and_leaves_tombstones_for_members(): void
    {
        $owner = $this->signIn();
        $member = $this->makeUser();
        $shared = $this->makeList($owner);
        $this->share($shared, $member);
        $task = $this->makeTask($shared, ['assignee_id' => $owner->id]);

        // The owner is also a member of someone else's list, assigned a task there.
        $otherList = $this->makeList($member, ['name' => 'Member list']);
        $this->share($otherList, $owner);
        $assigned = $this->makeTask($otherList, ['assignee_id' => $owner->id]);

        $this->deleteJson('/api/v1/me')->assertNoContent();

        $this->assertNull(User::find($owner->id));
        $this->assertSoftDeleted('task_lists', ['id' => $shared->id]);
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertTrue(ListMember::withTrashed()->where('list_id', $shared->id)->where('user_id', $member->id)->first()->trashed());
        $this->assertNull($assigned->fresh()->assignee_id);
        $this->assertNotNull(TaskList::find($otherList->id));
    }
}
