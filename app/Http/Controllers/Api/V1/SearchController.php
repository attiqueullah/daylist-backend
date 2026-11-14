<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Services\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, Access $access): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'include_completed' => ['sometimes', 'boolean'],
        ]);

        $like = '%'.addcslashes($data['q'], '%_\\').'%';

        $tasks = $access->tasks($request->user())
            ->when(! $request->boolean('include_completed'), fn (Builder $q) => $q->where('is_completed', false))
            ->where(fn (Builder $q) => $q->where('title', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('steps', fn (Builder $s) => $s->where('title', 'like', $like)))
            ->with('list:id,name,emoji,theme_color')
            ->withCount('steps')
            ->orderBy('is_completed')->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        $grouped = $tasks->groupBy('list_id')->map(fn ($items) => [
            'list' => [
                'id' => $items->first()->list->id,
                'name' => $items->first()->list->name,
                'emoji' => $items->first()->list->emoji,
                'theme_color' => $items->first()->list->theme_color,
            ],
            'tasks' => TaskResource::collection($items)->resolve($request),
        ])->values();

        return response()->json(['data' => $grouped, 'meta' => ['total' => $tasks->count()]]);
    }
}
