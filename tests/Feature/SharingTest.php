<?php

namespace Tests\Feature;

use App\Models\ListMember;
use App\Models\ShareInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_preview_and_join(): void
    {
        $owner = $this->signIn($this->makeUser(['name' => 'Ayesha']));
        $list = $this->makeList($owner, ['emoji' => '🛒']);
        $this->makeTask($list, ['title' => 'Eggs']);

        $invite = $this->postJson("/api/v1/lists/{$list->id}/invites")->assertCreated()->json('data');
        $this->assertStringEndsWith('/'.$invite['token'], $invite['url']);

        $friend = $this->makeUser();
        Sanctum::actingAs($friend);

        $this->getJson("/api/v1/invites/{$invite['token']}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Groceries')
            ->assertJsonPath('data.owner_name', 'Ayesha')
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.already_member', false);

        $this->postJson("/api/v1/invites/{$invite['token']}/accept")
            ->assertOk()
            ->assertJsonPath('data.id', $list->id)
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.member_count', 2);

        // Accepting twice is harmless.
        $this->postJson("/api/v1/invites/{$invite['token']}/accept")->assertOk();
        $this->assertSame(2, ListMember::where('list_id', $list->id)->count());

        // The member now sees the list and its tasks.
        $this->getJson("/api/v1/lists/{$list->id}/tasks")->assertOk()->assertJsonPath('data.0.title', 'Eggs');
        $this->getJson("/api/v1/lists/{$list->id}/members")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_default_list_cannot_be_shared(): void
    {
        $user = $this->signIn();
        $this->postJson("/api/v1/lists/{$user->defaultList()->id}/invites")->assertUnprocessable();
    }

    public function test_bad_expired_and_revoked_links(): void
    {
        $owner = $this->signIn();
        $list = $this->makeList($owner);
        $expired = ShareInvite::create(['list_id' => $list->id, 'token' => str_repeat('e', 43), 'created_by' => $owner->id, 'expires_at' => now()->subDay()]);

        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/invites/'.str_repeat('x', 43))->assertNotFound();
        $this->postJson("/api/v1/invites/{$expired->token}/accept")->assertStatus(410);
    }

    public function test_limit_access_blocks_new_people(): void
    {
        $owner = $this->signIn();
        $list = $this->makeList($owner);
        $token = $this->postJson("/api/v1/lists/{$list->id}/invites")->json('data.token');
        $this->patchJson("/api/v1/lists/{$list->id}/sharing", ['limited' => true])->assertOk()->assertJsonPath('data.sharing_limited', true);

        Sanctum::actingAs($this->makeUser());
        $this->postJson("/api/v1/invites/{$token}/accept")->assertForbidden();
    }

    public function test_stop_sharing_removes_members_and_their_assignments(): void
    {
        $owner = $this->signIn();
        $member = $this->makeUser();
        $list = $this->makeList($owner);
        $token = $this->postJson("/api/v1/lists/{$list->id}/invites")->json('data.token');
        $this->share($list, $member);
        $task = $this->makeTask($list, ['assignee_id' => $member->id]);

        $this->deleteJson("/api/v1/lists/{$list->id}/sharing")->assertNoContent();

        $this->assertFalse($list->fresh()->is_shared);
        $this->assertNull($task->fresh()->assignee_id);
        $this->assertFalse($list->hasMember($member));

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/lists/{$list->id}")->assertNotFound();
        $this->postJson("/api/v1/invites/{$token}/accept")->assertStatus(410);
    }

    public function test_member_leaves_and_owner_removes(): void
    {
        $owner = $this->makeUser();
        $list = $this->makeList($owner);
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->share($list, $a);
        $this->share($list, $b);

        // A member cannot remove someone else, and the owner cannot leave.
        Sanctum::actingAs($a);
        $this->deleteJson("/api/v1/lists/{$list->id}/members/{$b->id}")->assertForbidden();
        $this->deleteJson("/api/v1/lists/{$list->id}/members/{$a->id}")->assertNoContent();
        $this->getJson("/api/v1/lists/{$list->id}")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/lists/{$list->id}/members/{$owner->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/lists/{$list->id}/members/{$b->id}")->assertNoContent();
        $this->assertFalse($list->fresh()->is_shared);
    }

    public function test_rejoining_restores_the_same_membership_row(): void
    {
        $owner = $this->signIn();
        $list = $this->makeList($owner);
        $token = $this->postJson("/api/v1/lists/{$list->id}/invites")->json('data.token');
        $friend = $this->makeUser();

        Sanctum::actingAs($friend);
        $this->postJson("/api/v1/invites/{$token}/accept")->assertOk();
        $firstId = $list->membershipFor($friend)->id;
        $this->deleteJson("/api/v1/lists/{$list->id}/members/{$friend->id}")->assertNoContent();
        $this->postJson("/api/v1/invites/{$token}/accept")->assertOk();

        $this->assertSame($firstId, $list->membershipFor($friend)->id);
    }

    public function test_assign_only_to_members_and_mute(): void
    {
        $owner = $this->signIn();
        $member = $this->makeUser();
        $list = $this->makeList($owner);
        $this->share($list, $member);
        $task = $this->makeTask($list);

        $this->patchJson("/api/v1/tasks/{$task->id}", ['assignee_id' => $member->id])->assertOk()->assertJsonPath('data.assignee_id', $member->id);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['assignee_id' => $this->makeUser()->id])->assertUnprocessable();

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/smart-lists/assigned/tasks')->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/lists/{$list->id}/members/me", ['notifications_muted' => true])
            ->assertOk()->assertJsonPath('data.notifications_muted', true);
    }

    public function test_members_can_edit_tasks_but_strangers_cannot(): void
    {
        $owner = $this->makeUser();
        $list = $this->makeList($owner);
        $task = $this->makeTask($list);
        $member = $this->makeUser();
        $this->share($list, $member);

        Sanctum::actingAs($member);
        $this->patchJson("/api/v1/tasks/{$task->id}", ['is_completed' => true])->assertOk()->assertJsonPath('data.completed_by', $member->id);

        Sanctum::actingAs($this->makeUser());
        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/lists/{$list->id}/invites")->assertNotFound();
    }
}
