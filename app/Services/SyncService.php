<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\ListGroup;
use App\Models\ListMember;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Iso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Pull-based sync. The app keeps the cursor from the previous call and asks
 * for everything it can see that changed after it, deletions included.
 */
class SyncService
{
    /**
     * @return array{cursor: string, full_resync: bool, groups: mixed, lists: mixed, memberships: mixed,
     *               tasks: mixed, steps: mixed, attachments: mixed, users: mixed}
     */
    public function changes(User $user, ?Carbon $since): array
    {
        $cursor = Iso::now();
        $fullResync = false;

        if ($since !== null && $since->lt($cursor->copy()->subDays(config('daylist.purge_after_days')))) {
            // Tombstones older than this may be purged already: start over.
            $since = null;
            $fullResync = true;
        }

        $full = $since === null;
        $after = $full ? null : Iso::db($since->copy()->subSeconds(config('daylist.sync_overlap_seconds')));

        $changedSince = fn (Builder $q) => $full ? $q : $q->where('updated_at', '>', $after);
        // On a full sync, deleted rows are of no use to the device.
        $scope = fn (Builder $q) => $full ? $q : $q->withTrashed();

        // My own memberships, including ones I lost (tombstones tell the app to drop the list).
        $myMemberships = $scope(ListMember::query())->where('user_id', $user->id)->tap($changedSince)->get();

        $activeListIds = ListMember::where('user_id', $user->id)->whereHas('list')->pluck('list_id')->all();

        // Lists I joined since the cursor: send all of their contents, whatever their age.
        $newListIds = $full ? [] : $myMemberships
            ->filter(fn (ListMember $m) => $m->deleted_at === null && $m->created_at->gt($since->copy()->subSeconds(config('daylist.sync_overlap_seconds'))))
            ->pluck('list_id')->all();

        $inScope = function (Builder $q, string $listColumn) use ($activeListIds, $newListIds, $full, $after) {
            $q->whereIn($listColumn, $activeListIds);
            if (! $full) {
                $q->where(fn (Builder $w) => $w->where($q->getModel()->getTable().'.updated_at', '>', $after)
                    ->orWhereIn($listColumn, $newListIds));
            }

            return $q;
        };

        $lostListIds = $myMemberships->whereNotNull('deleted_at')->pluck('list_id')->all();
        $lists = $scope(TaskList::query())
            ->where(fn (Builder $q) => $inScope($q, 'id')->orWhereIn('id', $lostListIds))
            ->get();

        $otherMemberships = $scope(ListMember::query())
            ->where('user_id', '!=', $user->id)
            ->tap(fn (Builder $q) => $inScope($q, 'list_id'))
            ->get();

        $tasks = $scope(Task::query())->tap(fn (Builder $q) => $inScope($q, 'list_id'))->get();

        $steps = $scope(Step::query())
            ->whereHas('task', fn (Builder $q) => $q->withTrashed()->whereIn('list_id', $activeListIds))
            ->tap(fn (Builder $q) => $full ? $q : $q->where(fn (Builder $w) => $w->where('steps.updated_at', '>', $after)
                ->orWhereHas('task', fn (Builder $t) => $t->withTrashed()->whereIn('list_id', $newListIds))))
            ->get();

        $attachments = $scope(Attachment::query())
            ->whereHas('task', fn (Builder $q) => $q->withTrashed()->whereIn('list_id', $activeListIds))
            ->tap(fn (Builder $q) => $full ? $q : $q->where(fn (Builder $w) => $w->where('attachments.updated_at', '>', $after)
                ->orWhereHas('task', fn (Builder $t) => $t->withTrashed()->whereIn('list_id', $newListIds))))
            ->get();

        $groups = $scope(ListGroup::query())->where('user_id', $user->id)->tap($changedSince)->get();

        $memberships = $myMemberships->concat($otherMemberships);
        $users = User::whereIn('id', $memberships->pluck('user_id')->unique())->get();

        return [
            'cursor' => Iso::format($cursor),
            'full_resync' => $fullResync,
            'groups' => $groups,
            'lists' => $lists,
            'memberships' => $memberships,
            'tasks' => $tasks,
            'steps' => $steps,
            'attachments' => $attachments,
            'users' => $users,
        ];
    }
}
