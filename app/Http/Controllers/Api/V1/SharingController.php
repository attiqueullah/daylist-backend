<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Http\Resources\TaskListResource;
use App\Models\ListMember;
use App\Models\ShareInvite;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\ListService;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Invite links, joining, limiting access, stopping sharing, and members. */
class SharingController extends Controller
{
    public function __construct(private ListService $lists) {}

    public function createInvite(Request $request, TaskList $list): JsonResponse
    {
        $user = $request->user();
        $this->membership($user, $list);
        abort_if($list->is_default, 422, 'The default Tasks list cannot be shared.');
        abort_if(! $list->isOwnedBy($user) && $list->sharing_limited, 403, 'The owner has limited access to this list.');

        $data = $request->validate(['expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365']]);

        $invite = ShareInvite::create([
            'list_id' => $list->id,
            'token' => Str::random(43),
            'created_by' => $user->id,
            'expires_at' => isset($data['expires_in_days']) ? now()->addDays($data['expires_in_days']) : null,
        ]);

        return response()->json(['data' => [
            'token' => $invite->token,
            'url' => $invite->url(),
            'expires_at' => Iso::format($invite->expires_at),
        ]], 201);
    }

    public function preview(Request $request, string $token): JsonResponse
    {
        $invite = $this->usableInvite($token);
        $list = $invite->list;

        return response()->json(['data' => [
            'list_id' => $list->id,
            'name' => $list->name,
            'emoji' => $list->emoji,
            'theme_color' => $list->theme_color,
            'owner_name' => $list->owner?->name,
            'member_count' => $list->members()->count(),
            'already_member' => $list->hasMember($request->user()),
            'access_limited' => $list->sharing_limited,
        ]]);
    }

    public function accept(Request $request, string $token): TaskListResource
    {
        $user = $request->user();
        $invite = $this->usableInvite($token);
        $list = $invite->list;

        if (! $list->hasMember($user)) {
            abort_if($list->sharing_limited, 403, 'The owner has limited access to this list.');

            DB::transaction(function () use ($list, $user) {
                $previous = ListMember::withTrashed()->where('list_id', $list->id)->where('user_id', $user->id)->first();
                $attributes = [
                    'role' => 'member',
                    'group_id' => null,
                    'sort_order' => $this->lists->nextListPosition($user),
                    'notifications_muted' => false,
                    'joined_at' => Iso::now(),
                ];

                if ($previous) {
                    // Rejoining after leaving: bring the row back so ids stay stable.
                    $previous->restore();
                    $previous->update($attributes);
                } else {
                    ListMember::create($attributes + ['list_id' => $list->id, 'user_id' => $user->id]);
                }

                $list->update(['is_shared' => true]);
            });
        }

        $list->setRelation('membership', $list->membershipFor($user));

        return new TaskListResource($list->loadCount('members')->load('members.user'));
    }

    public function updateSharing(Request $request, TaskList $list): TaskListResource
    {
        $this->ownerOnly($request->user(), $list);
        $data = $request->validate(['limited' => ['required', 'boolean']]);

        $list->update(['sharing_limited' => $data['limited']]);

        return new TaskListResource($list);
    }

    /** Stop sharing: links stop working and every member except the owner is removed. */
    public function stopSharing(Request $request, TaskList $list): Response
    {
        $this->ownerOnly($request->user(), $list);

        DB::transaction(function () use ($list) {
            $now = Iso::db();
            ShareInvite::where('list_id', $list->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);

            $memberIds = ListMember::where('list_id', $list->id)->where('role', 'member')->pluck('user_id');
            Task::where('list_id', $list->id)->whereIn('assignee_id', $memberIds)->update(['assignee_id' => null, 'updated_at' => $now]);
            ListMember::where('list_id', $list->id)->where('role', 'member')->update(['deleted_at' => $now, 'updated_at' => $now]);

            $list->update(['is_shared' => false, 'sharing_limited' => false]);
        });

        return response()->noContent();
    }

    public function members(Request $request, TaskList $list): AnonymousResourceCollection
    {
        $this->membership($request->user(), $list);

        return MemberResource::collection($list->members()->with('user')->orderBy('joined_at')->get());
    }

    /** The owner removes someone, or a member leaves (removes themself). */
    public function removeMember(Request $request, TaskList $list, string $userId): Response
    {
        $user = $request->user();
        $this->membership($user, $list);

        $isSelf = $userId === $user->id;
        abort_if($isSelf && $list->isOwnedBy($user), 422, 'The owner cannot leave their own list. Delete it or stop sharing instead.');
        abort_unless($isSelf || $list->isOwnedBy($user), 403, 'Only the owner can remove other members.');

        $target = $list->membershipFor($userId);
        abort_if($target === null, 404);

        DB::transaction(function () use ($list, $target) {
            Task::where('list_id', $list->id)->where('assignee_id', $target->user_id)
                ->update(['assignee_id' => null, 'updated_at' => Iso::db()]);
            $target->delete();

            if ($list->members()->count() <= 1) {
                $list->update(['is_shared' => false]);
            }
        });

        return response()->noContent();
    }

    public function updateMyMembership(Request $request, TaskList $list): MemberResource
    {
        $membership = $this->membership($request->user(), $list);
        $membership->update($request->validate(['notifications_muted' => ['required', 'boolean']]));

        return new MemberResource($membership->load('user'));
    }

    private function usableInvite(string $token): ShareInvite
    {
        $invite = ShareInvite::where('token', $token)->first();
        abort_if($invite === null || $invite->list === null, 404, 'This invite link is not valid.');
        abort_unless($invite->isUsable(), 410, 'This invite link has expired or was turned off.');

        return $invite;
    }

    private function membership(User $user, TaskList $list): ListMember
    {
        $membership = $list->membershipFor($user);
        abort_if($membership === null, 404);

        return $membership;
    }

    private function ownerOnly(User $user, TaskList $list): void
    {
        $this->membership($user, $list);
        abort_unless($list->isOwnedBy($user), 403, 'Only the owner can change sharing.');
    }
}
