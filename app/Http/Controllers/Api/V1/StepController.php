<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StepResource;
use App\Http\Resources\TaskResource;
use App\Models\Step;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class StepController extends Controller
{
    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->ensureVisible($request, $task);

        return StepResource::collection($task->steps()->get());
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->ensureVisible($request, $task);
        $data = $request->validate([
            'id' => ['sometimes', 'uuid'],
            'title' => ['required', 'string', 'max:500'],
            'is_completed' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'numeric'],
        ]);

        $user = $request->user();
        if ($existing = $this->existingForReplay(Step::class, $data['id'] ?? null, fn ($s) => $s->task?->list?->hasMember($user))) {
            return (new StepResource($existing))->response()->setStatusCode(200);
        }

        $data['sort_order'] ??= (float) Step::where('task_id', $task->id)->max('sort_order') + 1;
        $step = Step::create(array_merge($data, ['task_id' => $task->id]));
        $task->touch();

        return (new StepResource($step))->response()->setStatusCode(201);
    }

    public function update(Request $request, Step $step): StepResource
    {
        $this->ensureVisible($request, $step->task);

        $step->update($request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'is_completed' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'numeric'],
        ]));

        return new StepResource($step);
    }

    public function destroy(Request $request, Step $step): Response
    {
        $this->ensureVisible($request, $step->task);
        $step->delete();

        return response()->noContent();
    }

    /** Turns a step into its own task in the same list (UC-24). */
    public function promote(Request $request, Step $step): JsonResponse
    {
        $task = $step->task;
        $this->ensureVisible($request, $task);

        $new = DB::transaction(function () use ($request, $step, $task) {
            $new = Task::create([
                'list_id' => $task->list_id,
                'created_by' => $request->user()->id,
                'title' => $step->title,
                'is_completed' => $step->is_completed,
                'completed_at' => $step->is_completed ? now() : null,
                'sort_order' => $task->sort_order + 0.5,
            ]);
            $step->delete();

            return $new;
        });

        return (new TaskResource($new))->response()->setStatusCode(201);
    }

    private function ensureVisible(Request $request, ?Task $task): void
    {
        abort_unless($task && $task->list && $task->list->hasMember($request->user()), 404);
    }
}
