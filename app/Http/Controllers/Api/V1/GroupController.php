<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\ListGroup;
use App\Models\ListMember;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return GroupResource::collection(
            ListGroup::where('user_id', $request->user()->id)->orderBy('sort_order')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'numeric'],
            'is_collapsed' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();

        if ($existing = $this->existingForReplay(ListGroup::class, $data['id'] ?? null, fn ($g) => $g->user_id === $user->id)) {
            return (new GroupResource($existing))->response()->setStatusCode(200);
        }

        $data['sort_order'] ??= (float) ListGroup::where('user_id', $user->id)->max('sort_order') + 1;
        $group = ListGroup::create(array_merge($data, ['user_id' => $user->id]));

        return (new GroupResource($group))->response()->setStatusCode(201);
    }

    public function update(Request $request, ListGroup $group): GroupResource
    {
        $this->authorizeOwner($request, $group);

        $group->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'numeric'],
            'is_collapsed' => ['sometimes', 'boolean'],
        ]));

        return new GroupResource($group);
    }

    /** Deleting a group keeps its lists; they move out of the group. */
    public function destroy(Request $request, ListGroup $group): Response
    {
        $this->authorizeOwner($request, $group);

        DB::transaction(function () use ($group) {
            ListMember::where('group_id', $group->id)->update(['group_id' => null, 'updated_at' => Iso::db()]);
            $group->delete();
        });

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, ListGroup $group): void
    {
        abort_unless($group->user_id === $request->user()->id, 404);
    }
}
