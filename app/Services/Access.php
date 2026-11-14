<?php

namespace App\Services;

use App\Models\ListMember;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Who can see what, plus the user's local "today". */
class Access
{
    /** Ids of lists the user currently belongs to (owner or member). */
    public function listIds(User $user): array
    {
        return ListMember::query()
            ->where('user_id', $user->id)
            ->whereHas('list')
            ->pluck('list_id')
            ->all();
    }

    /** Tasks the user can see. */
    public function tasks(User $user): Builder
    {
        return Task::query()->whereIn('list_id', $this->listIds($user));
    }

    public function canSeeList(User $user, TaskList $list): bool
    {
        return $list->hasMember($user);
    }

    /**
     * The client's local date. The app sends ?today=YYYY-MM-DD; without it we
     * use the user's saved timezone.
     */
    public function today(User $user, ?string $requested = null): Carbon
    {
        if ($requested !== null) {
            $validator = Validator::make(['today' => $requested], ['today' => ['date_format:Y-m-d']]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['today' => 'The today parameter must be a date in YYYY-MM-DD format.']);
            }

            return Carbon::createFromFormat('!Y-m-d', $requested, 'UTC');
        }

        $local = Carbon::now($user->timezone ?: 'UTC')->format('Y-m-d');

        return Carbon::createFromFormat('!Y-m-d', $local, 'UTC');
    }
}
