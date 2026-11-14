<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Attachment;
use App\Models\ListMember;
use App\Models\Task;
use App\Models\TaskList;
use App\Services\ListService;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'timezone:all'],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'settings' => ['sometimes', 'array'],
            'settings.add_to_top' => ['sometimes', 'boolean'],
            'settings.starred_to_top' => ['sometimes', 'boolean'],
            'settings.completion_sound' => ['sometimes', 'boolean'],
            'settings.confirm_delete' => ['sometimes', 'boolean'],
            'settings.link_previews' => ['sometimes', 'boolean'],
            'settings.recognise_dates' => ['sometimes', 'boolean'],
            'settings.remove_recognised_dates' => ['sometimes', 'boolean'],
            'settings.carry_over_my_day' => ['sometimes', 'boolean'],
            'settings.week_start' => ['sometimes', Rule::in(['monday', 'sunday', 'saturday'])],
            'settings.default_reminder_time' => ['sometimes', 'date_format:H:i'],
            'settings.hidden_smart_lists' => ['sometimes', 'array'],
            'settings.hidden_smart_lists.*' => [Rule::in(['my-day', 'important', 'planned', 'assigned', 'all', 'completed'])],
        ]);

        $user = $request->user();
        if (isset($data['settings'])) {
            $known = array_keys(config('daylist.default_settings'));
            $data['settings'] = array_merge(
                $user->settings ?? [],
                array_intersect_key($data['settings'], array_flip($known)),
            );
        }

        $user->update($data);

        return new UserResource($user->fresh());
    }

    /** Deletes the account and everything it owns (App Store requirement). */
    public function destroy(Request $request, ListService $lists): Response
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $lists) {
            $owned = TaskList::where('owner_id', $user->id)->get();
            foreach ($owned as $list) {
                $files = Attachment::withTrashed()->whereHas('task', fn ($q) => $q->withTrashed()->where('list_id', $list->id))->get();
                foreach ($files as $file) {
                    Storage::disk($file->disk)->delete($file->path);
                }
                // Members get tombstones so the list disappears from their devices.
                $lists->delete($list);
            }

            // Touch rows the foreign keys would otherwise change silently, so other members sync them.
            $now = Iso::db();
            Task::where('assignee_id', $user->id)->update(['assignee_id' => null, 'updated_at' => $now]);
            // Their memberships in other people's lists go with the account; bump
            // those lists so members' devices refresh the member list.
            $otherListIds = ListMember::where('user_id', $user->id)->pluck('list_id');
            TaskList::whereIn('id', $otherListIds)->update(['updated_at' => $now]);

            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}
