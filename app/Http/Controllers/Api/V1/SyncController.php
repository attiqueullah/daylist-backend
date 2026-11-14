<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\GroupResource;
use App\Http\Resources\MemberResource;
use App\Http\Resources\StepResource;
use App\Http\Resources\TaskListResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserResource;
use App\Models\ListMember;
use App\Models\TaskList;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    public function __invoke(Request $request, SyncService $sync): JsonResponse
    {
        $request->validate(['since' => ['sometimes', 'nullable', 'date']]);
        $user = $request->user();
        $since = $request->filled('since') ? Carbon::parse($request->query('since'))->utc() : null;

        $changes = $sync->changes($user, $since);

        // A list's resource needs this user's membership for group and order.
        $mine = ListMember::withTrashed()->where('user_id', $user->id)->get()->keyBy('list_id');
        $changes['lists']->each(fn (TaskList $list) => $list->setRelation('membership', $mine[$list->id] ?? null));

        return response()->json(['data' => [
            'cursor' => $changes['cursor'],
            'full_resync' => $changes['full_resync'],
            'groups' => GroupResource::collection($changes['groups'])->resolve($request),
            'lists' => TaskListResource::collection($changes['lists'])->resolve($request),
            'memberships' => MemberResource::collection($changes['memberships'])->resolve($request),
            'tasks' => TaskResource::collection($changes['tasks'])->resolve($request),
            'steps' => StepResource::collection($changes['steps'])->resolve($request),
            'attachments' => AttachmentResource::collection($changes['attachments'])->resolve($request),
            'users' => UserResource::collection($changes['users'])->resolve($request),
        ]]);
    }
}
