<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskList;
use App\Services\Access;
use App\Services\ListService;
use App\Services\RecurrenceService;
use App\Services\TaskService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function __construct(
        private TaskService $tasks,
        private Access $access,
        private RecurrenceService $recurrence,
    ) {}

    public function index(Request $request, TaskList $list): AnonymousResourceCollection
    {
        abort_unless($list->hasMember($request->user()), 404);
        $includeCompleted = $request->boolean('include_completed', true);

        $query = Task::where('list_id', $list->id)->withCount('steps')
            ->when(! $includeCompleted, fn (Builder $q) => $q->where('is_completed', false));

        $this->applySort($query, $list, $request->user()->setting('starred_to_top'));

        return TaskResource::collection($query->get());
    }

    public function store(Request $request, TaskList $list): JsonResponse
    {
        abort_unless($list->hasMember($request->user()), 404);

        return $this->createIn($request, $list);
    }

    /** Quick add from a smart list: goes to list_id, or the user's default Tasks list. */
    public function storeQuick(Request $request, ListService $lists): JsonResponse
    {
        $request->validate(['list_id' => ['sometimes', 'nullable', 'uuid']]);
        $user = $request->user();

        $list = $request->filled('list_id')
            ? TaskList::findOrFail($request->input('list_id'))
            : $lists->createDefaultList($user);
        abort_unless($list->hasMember($user), 404);

        return $this->createIn($request, $list);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->ensureVisible($request, $task);

        return new TaskResource($task->load(['steps', 'attachments']));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->ensureVisible($request, $task);
        $data = $request->validate(array_merge($this->rules(), [
            'title' => ['sometimes', 'string', 'max:500'],
            'list_id' => ['sometimes', 'uuid'],
            'is_completed' => ['sometimes', 'boolean'],
        ]));

        $user = $request->user();
        [$task, $next] = $this->tasks->update($user, $task, $data, $this->access->today($user, $request->query('today')));

        $payload = (new TaskResource($task->load(['steps', 'attachments'])))->resolve($request);
        $response = ['data' => $payload];
        if ($next) {
            $response['next_task'] = (new TaskResource($next->load('steps')))->resolve($request);
        }

        return response()->json($response);
    }

    public function destroy(Request $request, Task $task): Response
    {
        $this->ensureVisible($request, $task);
        $this->tasks->delete($task);

        return response()->noContent();
    }

    private function createIn(Request $request, TaskList $list): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(array_merge($this->rules(), [
            'id' => ['sometimes', 'uuid'],
            'title' => ['required', 'string', 'max:500'],
        ]));

        if ($existing = $this->existingForReplay(Task::class, $data['id'] ?? null, fn ($t) => $t->list->hasMember($user))) {
            return (new TaskResource($existing))->response()->setStatusCode(200);
        }

        $task = $this->tasks->create($user, $list, $data, $this->access->today($user, $request->query('today')));

        return (new TaskResource($task->fresh()->load('steps')))->response()->setStatusCode(201);
    }

    private function ensureVisible(Request $request, Task $task): void
    {
        abort_unless($task->list && $task->list->hasMember($request->user()), 404);
    }

    private function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'is_important' => ['sometimes', 'boolean'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reminder_at' => ['sometimes', 'nullable', 'date'],
            'recurrence' => ['sometimes', 'nullable', 'string', 'max:255', $this->recurrenceRule()],
            'my_day_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
            'sort_order' => ['sometimes', 'numeric'],
        ];
    }

    private function recurrenceRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && ($error = $this->recurrence->validationError((string) $value))) {
                $fail($error);
            }
        };
    }

    private function applySort(Builder $query, TaskList $list, bool $starredToTop): void
    {
        $direction = $list->sort_direction === 'desc' ? 'desc' : 'asc';
        $query->orderBy('is_completed');
        if ($starredToTop && $list->sort_mode !== 'importance') {
            $query->orderByDesc('is_important');
        }

        match ($list->sort_mode) {
            'importance' => $query->orderBy('is_important', $direction === 'asc' ? 'desc' : 'asc'),
            'due_date' => $query->orderByRaw('due_date IS NULL')->orderBy('due_date', $direction),
            'alphabetical' => $query->orderBy('title', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            'my_day' => $query->orderByRaw('my_day_date IS NULL')->orderBy('my_day_date', $direction === 'asc' ? 'desc' : 'asc'),
            default => null,
        };

        $query->orderBy('sort_order')->orderBy('created_at');
    }
}
