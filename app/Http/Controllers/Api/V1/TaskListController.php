<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskListResource;
use App\Models\ListGroup;
use App\Models\ListMember;
use App\Models\TaskList;
use App\Models\User;
use App\Services\ListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TaskListController extends Controller
{
    public function __construct(private ListService $lists) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $memberships = ListMember::where('user_id', $request->user()->id)->get()->keyBy('list_id');

        $lists = $this->withCounts(TaskList::whereIn('id', $memberships->keys()))->get()
            ->each(fn (TaskList $list) => $list->setRelation('membership', $memberships[$list->id]))
            ->sortBy(fn (TaskList $list) => [$list->is_default ? 0 : 1, $list->membership->sort_order])
            ->values();

        return TaskListResource::collection($lists);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(array_merge($this->listRules(), [
            'id' => ['sometimes', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
        ], $this->placementRules($user)));

        if ($existing = $this->existingForReplay(TaskList::class, $data['id'] ?? null, fn ($l) => $l->hasMember($user))) {
            return (new TaskListResource($existing))->response()->setStatusCode(200);
        }

        $list = $this->lists->create($user, $data);

        return (new TaskListResource($this->reload($list)))->response()->setStatusCode(201);
    }

    public function show(Request $request, TaskList $list): TaskListResource
    {
        $this->ensureMember($request->user(), $list);

        return new TaskListResource($this->reload($list)->load(['members.user']));
    }

    public function update(Request $request, TaskList $list): TaskListResource
    {
        $user = $request->user();
        $membership = $this->ensureMember($user, $list);

        $data = $request->validate(array_merge($this->listRules(), [
            'name' => ['sometimes', 'string', 'max:255'],
        ], $this->placementRules($user)));

        if ($list->is_default && isset($data['name']) && $data['name'] !== $list->name) {
            abort(422, 'The default Tasks list cannot be renamed.');
        }

        $list->update(array_intersect_key($data, $this->listRules() + ['name' => true]));
        $membership->update(array_intersect_key($data, array_flip(['group_id', 'sort_order'])));

        return new TaskListResource($this->reload($list));
    }

    public function destroy(Request $request, TaskList $list): Response
    {
        $this->ensureMember($request->user(), $list);
        abort_unless($list->isOwnedBy($request->user()), 403, 'Only the owner can delete a list. Members can leave it instead.');
        abort_if($list->is_default, 422, 'The default Tasks list cannot be deleted.');

        $this->lists->delete($list);

        return response()->noContent();
    }

    public function duplicate(Request $request, TaskList $list): JsonResponse
    {
        $user = $request->user();
        $this->ensureMember($user, $list);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255']]);

        $copy = $this->lists->duplicate($user, $list, $data['name'] ?? null);

        return (new TaskListResource($this->reload($copy)))->response()->setStatusCode(201);
    }

    private function ensureMember(User $user, TaskList $list): ListMember
    {
        $membership = $list->membershipFor($user);
        abort_if($membership === null, 404);

        return $membership;
    }

    private function reload(TaskList $list): TaskList
    {
        $fresh = $this->withCounts(TaskList::whereKey($list->id))->firstOrFail();
        $fresh->setRelation('membership', $fresh->membershipFor(request()->user()));

        return $fresh;
    }

    private function withCounts($query)
    {
        return $query->withCount(['members', 'tasks as open_tasks_count' => fn ($q) => $q->where('is_completed', false)]);
    }

    private function listRules(): array
    {
        return [
            'emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'theme_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'theme_image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_mode' => ['sometimes', Rule::in(TaskList::SORT_MODES)],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'show_completed' => ['sometimes', 'boolean'],
        ];
    }

    private function placementRules(User $user): array
    {
        return [
            'group_id' => ['sometimes', 'nullable', 'uuid', Rule::exists(ListGroup::class, 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'numeric'],
        ];
    }
}
