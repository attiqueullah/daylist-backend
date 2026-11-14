<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskList;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    private function sync(?string $cursor = null): array
    {
        return $this->getJson('/api/v1/sync'.($cursor ? '?since='.urlencode($cursor) : ''))->assertOk()->json('data');
    }

    private function ids(array $rows): array
    {
        return collect($rows)->pluck('id')->sort()->values()->all();
    }

    public function test_full_sync_returns_everything_visible(): void
    {
        $this->travelTo('2026-11-14 08:00:00');
        $user = $this->signIn();
        $group = $user->groups()->create(['name' => 'Home']);
        $list = $this->makeList($user, ['group_id' => $group->id]);
        $task = $this->makeTask($list);
        Step::create(['task_id' => $task->id, 'title' => 'Step']);
        $this->makeTask($this->makeList($this->makeUser()), ['title' => 'Not mine']);
        $gone = $this->makeTask($list, ['title' => 'Deleted before']);
        $gone->delete();

        $data = $this->sync();

        $this->assertFalse($data['full_resync']);
        $this->assertSame('2026-11-14T08:00:00.000000Z', $data['cursor']);
        $this->assertCount(2, $data['lists']); // Tasks + Groceries
        $this->assertSame([$task->id], $this->ids($data['tasks']));
        $this->assertCount(1, $data['steps']);
        $this->assertSame([$group->id], $this->ids($data['groups']));
        $this->assertSame($group->id, collect($data['lists'])->firstWhere('id', $list->id)['group_id']);
        $this->assertSame([$user->id], $this->ids($data['users']));
    }

    public function test_incremental_sync_returns_only_changes_and_tombstones(): void
    {
        $this->travelTo('2026-11-14 08:00:00');
        $user = $this->signIn();
        $list = $this->makeList($user);
        $old = $this->makeTask($list, ['title' => 'Old']);
        $toDelete = $this->makeTask($list, ['title' => 'Will be deleted']);
        $this->travelTo('2026-11-14 08:01:00');
        $cursor = $this->sync()['cursor'];

        $this->travelTo('2026-11-14 09:00:00');
        $this->patchJson("/api/v1/tasks/{$toDelete->id}", ['title' => 'Edited'])->assertOk();
        $this->deleteJson("/api/v1/tasks/{$toDelete->id}")->assertNoContent();
        $new = $this->postJson("/api/v1/lists/{$list->id}/tasks", ['title' => 'New'])->json('data.id');

        $this->travelTo('2026-11-14 09:01:00');
        $data = $this->sync($cursor);

        $this->assertSame($this->ids([['id' => $toDelete->id], ['id' => $new]]), $this->ids($data['tasks']));
        $deleted = collect($data['tasks'])->firstWhere('id', $toDelete->id);
        $this->assertNotNull($deleted['deleted_at']);
        $this->assertNotContains($old->id, $this->ids($data['tasks']));
        $this->assertSame([], $data['lists']);

        // Nothing changed since: empty but valid.
        $this->travelTo('2026-11-14 10:00:00');
        $again = $this->sync($data['cursor']);
        $this->assertSame([], $again['tasks']);
    }

    public function test_rows_written_just_before_the_cursor_are_sent_again(): void
    {
        $this->travelTo('2026-11-14 08:00:00');
        $user = $this->signIn();
        $list = $this->makeList($user);

        $this->travelTo('2026-11-14 08:00:58');
        $late = $this->makeTask($list, ['title' => 'Committed late']);

        // Cursor from a sync that ran at 08:01:00 but did not see this row yet.
        $data = $this->sync('2026-11-14T08:01:00.000000Z');
        $this->assertContains($late->id, $this->ids($data['tasks']));
    }

    public function test_deleted_list_and_lost_access_reach_the_device(): void
    {
        $this->travelTo('2026-11-14 08:00:00');
        $owner = $this->makeUser();
        $shared = $this->makeList($owner, ['name' => 'Shared']);
        $member = $this->signIn();
        $this->share($shared, $member);
        $mine = $this->makeList($member, ['name' => 'Mine']);
        $cursor = $this->sync()['cursor'];

        $this->travelTo('2026-11-14 09:00:00');
        $this->deleteJson("/api/v1/lists/{$mine->id}")->assertNoContent();
        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/lists/{$shared->id}/members/{$member->id}")->assertNoContent();

        Sanctum::actingAs($member);
        $data = $this->sync($cursor);

        $lists = collect($data['lists'])->keyBy('id');
        $this->assertNotNull($lists[$mine->id]['deleted_at']);
        $this->assertNull($lists[$shared->id]['deleted_at']);
        $myMembership = collect($data['memberships'])->firstWhere('list_id', $shared->id);
        $this->assertSame($member->id, $myMembership['user_id']);
        $this->assertNotNull($myMembership['deleted_at']);
    }

    public function test_joining_a_list_sends_its_older_contents(): void
    {
        $this->travelTo('2026-11-10 08:00:00');
        $owner = $this->makeUser();
        $list = $this->makeList($owner);
        $oldTask = $this->makeTask($list, ['title' => 'Made days ago']);
        Step::create(['task_id' => $oldTask->id, 'title' => 'Old step']);

        $this->travelTo('2026-11-14 08:00:00');
        $member = $this->signIn();
        $cursor = $this->sync()['cursor'];

        $this->travelTo('2026-11-14 09:00:00');
        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/lists/{$list->id}/invites")->json('data.token');
        Sanctum::actingAs($member);
        $this->postJson("/api/v1/invites/{$token}/accept")->assertOk();

        $data = $this->sync($cursor);

        $this->assertContains($list->id, $this->ids($data['lists']));
        $this->assertContains($oldTask->id, $this->ids($data['tasks']));
        $this->assertCount(1, $data['steps']);
        $this->assertContains($owner->id, $this->ids($data['users']));
    }

    public function test_cursor_older_than_retention_forces_full_resync(): void
    {
        $this->travelTo('2026-11-14 08:00:00');
        $this->signIn();

        $data = $this->sync('2026-09-01T00:00:00Z');
        $this->assertTrue($data['full_resync']);
        $this->assertCount(1, $data['lists']);

        $this->getJson('/api/v1/sync?since=yesterday-ish')->assertUnprocessable();
    }

    public function test_purge_removes_old_tombstones_and_files(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-01 08:00:00');
        $user = $this->makeUser();
        $list = $this->makeList($user);
        $task = $this->makeTask($list);
        Storage::disk('local')->put('attachments/x/file.txt', 'hello');
        $attachment = Attachment::create([
            'task_id' => $task->id, 'file_name' => 'file.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5,
            'disk' => 'local', 'path' => 'attachments/x/file.txt',
        ]);
        $recent = $this->makeTask($list, ['title' => 'Recent']);
        app(TaskService::class)->delete($task);

        $this->travelTo('2026-11-14 08:00:00');
        $recent->delete();

        $this->artisan('daylist:purge-deleted')->assertSuccessful();

        $this->assertNull(Task::withTrashed()->find($task->id));
        $this->assertNull(Attachment::withTrashed()->find($attachment->id));
        Storage::disk('local')->assertMissing('attachments/x/file.txt');
        $this->assertNotNull(Task::withTrashed()->find($recent->id));
        $this->assertNotNull(TaskList::find($list->id));
    }
}
