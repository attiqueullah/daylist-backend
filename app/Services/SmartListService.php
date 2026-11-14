<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** My Day, Important, Planned, Assigned to me, All and Completed are queries over tasks. */
class SmartListService
{
    public const NAMES = ['my-day', 'important', 'planned', 'assigned', 'all', 'completed'];

    public function __construct(private Access $access) {}

    public function query(User $user, string $name, Carbon $today): Builder
    {
        $base = $this->access->tasks($user);

        return match ($name) {
            'my-day' => $base->where('my_day_date', $today->format('Y-m-d')),
            'important' => $base->where('is_important', true)->where('is_completed', false),
            'planned' => $base->where('is_completed', false)
                ->where(fn (Builder $q) => $q->whereNotNull('due_date')->orWhereNotNull('reminder_at')),
            'assigned' => $base->where('assignee_id', $user->id)->where('is_completed', false),
            'all' => $base->where('is_completed', false),
            'completed' => $base->where('is_completed', true),
            default => throw new InvalidArgumentException("Unknown smart list {$name}"),
        };
    }

    public function tasks(User $user, string $name, Carbon $today): Collection
    {
        $query = $this->query($user, $name, $today)->withCount('steps');

        $query = match ($name) {
            'planned' => $query->orderByRaw('COALESCE(due_date, DATE(reminder_at)) asc')->orderBy('reminder_at'),
            'completed' => $query->orderByDesc('completed_at'),
            default => $query->orderBy('is_completed')->orderBy('sort_order')->orderBy('created_at'),
        };

        $tasks = $query->get();

        if ($name === 'planned') {
            $tasks->each(fn (Task $task) => $task->setAttribute('bucket', $this->plannedBucket($user, $task, $today)));
        }

        return $tasks;
    }

    public function counts(User $user, Carbon $today): array
    {
        $counts = [];
        foreach (self::NAMES as $name) {
            $counts[str_replace('-', '_', $name)] = $this->query($user, $name, $today)->count();
        }

        return $counts;
    }

    /** Planned groups: overdue, today, tomorrow, this_week (until the end of the user's week), later. */
    public function plannedBucket(User $user, Task $task, Carbon $today): string
    {
        $dateString = $task->due_date
            ?? $task->reminder_at?->copy()->setTimezone($user->timezone ?: 'UTC')->format('Y-m-d');
        $date = Carbon::createFromFormat('!Y-m-d', $dateString, 'UTC');

        $weekStartsOn = $user->setting('week_start') === 'sunday' ? Carbon::SUNDAY : Carbon::MONDAY;
        $endOfWeek = $today->copy()->endOfWeek($weekStartsOn === Carbon::SUNDAY ? Carbon::SATURDAY : Carbon::SUNDAY)->startOfDay();

        return match (true) {
            $date->lt($today) => 'overdue',
            $date->eq($today) => 'today',
            $date->eq($today->copy()->addDay()) => 'tomorrow',
            $date->lte($endOfWeek) => 'this_week',
            default => 'later',
        };
    }

    /** Lightbulb suggestions for My Day. */
    public function suggestions(User $user, Carbon $today): array
    {
        $todayString = $today->format('Y-m-d');
        $notInMyDay = fn (Builder $q) => $q->whereNull('my_day_date')->orWhere('my_day_date', '!=', $todayString);

        $yesterday = $this->access->tasks($user)
            ->where('is_completed', false)
            ->where('my_day_date', $today->copy()->subDay()->format('Y-m-d'))
            ->orderBy('sort_order')->get();

        $due = $this->access->tasks($user)
            ->where('is_completed', false)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $todayString)
            ->where($notInMyDay)
            ->whereNotIn('id', $yesterday->pluck('id'))
            ->orderBy('due_date')->get();

        $recent = $this->access->tasks($user)
            ->where('is_completed', false)
            ->where('created_at', '>=', $today->copy()->subDays(7)->format('Y-m-d'))
            ->where($notInMyDay)
            ->whereNotIn('id', $yesterday->pluck('id')->merge($due->pluck('id')))
            ->orderByDesc('created_at')->limit(20)->get();

        return ['yesterday' => $yesterday, 'due' => $due, 'recent' => $recent];
    }
}
