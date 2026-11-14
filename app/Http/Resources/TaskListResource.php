<?php

namespace App\Http\Resources;

use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A list as seen by the requesting user. group_id, sort_order and role come
 * from that user's membership row (set it as the "membership" relation).
 */
class TaskListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->relationLoaded('membership')
            ? $this->membership
            : ($request->user() ? $this->membershipFor($request->user()) : null);

        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'emoji' => $this->emoji,
            'theme_color' => $this->theme_color,
            'theme_image' => $this->theme_image,
            'sort_mode' => $this->sort_mode,
            'sort_direction' => $this->sort_direction,
            'show_completed' => $this->show_completed,
            'is_default' => $this->is_default,
            'is_shared' => $this->is_shared,
            'sharing_limited' => $this->sharing_limited,
            'role' => $membership?->role,
            'group_id' => $membership?->group_id,
            'sort_order' => $membership?->sort_order,
            'notifications_muted' => $membership?->notifications_muted,
            'member_count' => $this->whenCounted('members'),
            'open_task_count' => $this->whenCounted('open_tasks'),
            'members' => MemberResource::collection($this->whenLoaded('members')),
            'created_at' => Iso::format($this->created_at),
            'updated_at' => Iso::format($this->updated_at),
            'deleted_at' => Iso::format($this->deleted_at),
        ];
    }
}
