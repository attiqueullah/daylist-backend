<?php

namespace App\Services;

use App\Models\Step;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RRule\RRule;
use Throwable;

/**
 * Repeating tasks. A task stores an RRULE such as "FREQ=WEEKLY;BYDAY=MO,WE".
 * When it is completed, the next occurrence is created as a new open task.
 */
class RecurrenceService
{
    private const ALLOWED_PARTS = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'UNTIL', 'WKST'];

    private const ALLOWED_FREQ = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /** Returns null when valid, or a message explaining what is wrong. */
    public function validationError(string $rrule): ?string
    {
        $rrule = $this->normalise($rrule);
        if ($rrule === '') {
            return 'The recurrence rule is empty.';
        }

        $parts = [];
        foreach (explode(';', $rrule) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($value === null || $value === '') {
                return "Invalid recurrence part \"{$pair}\".";
            }
            $parts[strtoupper($key)] = $value;
        }

        if (! isset($parts['FREQ']) || ! in_array(strtoupper($parts['FREQ']), self::ALLOWED_FREQ, true)) {
            return 'FREQ must be DAILY, WEEKLY, MONTHLY or YEARLY.';
        }

        $unsupported = array_diff(array_keys($parts), self::ALLOWED_PARTS);
        if ($unsupported !== []) {
            return 'Unsupported recurrence part(s): '.implode(', ', $unsupported).'.';
        }

        try {
            new RRule($rrule, '2026-01-01');
        } catch (Throwable $e) {
            return 'Invalid recurrence rule: '.$e->getMessage();
        }

        return null;
    }

    public function normalise(string $rrule): string
    {
        $rrule = trim($rrule);
        if (stripos($rrule, 'RRULE:') === 0) {
            $rrule = substr($rrule, 6);
        }

        $rrule = strtoupper(rtrim($rrule, ';'));

        // Due dates are whole days, so an UNTIL with a time (as EventKit writes
        // it, e.g. 20261115T235959Z) is reduced to its date.
        return preg_replace('/UNTIL=(\d{8})T\d{6}Z?/', 'UNTIL=$1', $rrule);
    }

    /** The first occurrence strictly after $from, using $from as the series start. */
    public function nextDate(string $rrule, Carbon $from): ?Carbon
    {
        $rule = new RRule($this->normalise($rrule), $from->copy()->startOfDay()->format('Y-m-d'));
        $next = $rule->getOccurrencesAfter($from->copy()->startOfDay(), false, 1);

        return $next === [] ? null : Carbon::instance($next[0])->startOfDay();
    }

    /**
     * Creates the next occurrence of a just-completed task, once.
     * Returns the new task, or null if the task does not repeat or the series ended.
     */
    public function spawnNext(Task $task, Carbon $today): ?Task
    {
        if ($task->recurrence === null || $task->next_task_id !== null) {
            return null;
        }

        $base = $task->due_date ? Carbon::createFromFormat('!Y-m-d', $task->due_date, 'UTC') : $today->copy();
        $nextDue = $this->nextDate($task->recurrence, $base);
        if ($nextDue === null) {
            return null;
        }

        return DB::transaction(function () use ($task, $base, $nextDue) {
            $shift = (int) round(abs($base->diffInDays($nextDue)));

            $next = Task::create([
                'list_id' => $task->list_id,
                'created_by' => $task->created_by,
                'title' => $task->title,
                'notes' => $task->notes,
                'is_important' => $task->is_important,
                'due_date' => $nextDue->format('Y-m-d'),
                'reminder_at' => $task->reminder_at?->copy()->addDays($shift),
                'recurrence' => $task->recurrence,
                'assignee_id' => $task->assignee_id,
                'sort_order' => $task->sort_order,
            ]);

            foreach ($task->steps as $step) {
                Step::create([
                    'task_id' => $next->id,
                    'title' => $step->title,
                    'is_completed' => false,
                    'sort_order' => $step->sort_order,
                ]);
            }

            $task->forceFill(['next_task_id' => $next->id])->save();

            // Re-read so database defaults (is_completed = false, ...) are present.
            return $next->fresh();
        });
    }
}
