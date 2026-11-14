<?php

namespace App\Http\Resources;

use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isMe = $request->user()?->id === $this->user_id;

        return [
            'id' => $this->id,
            'list_id' => $this->list_id,
            'user_id' => $this->user_id,
            'role' => $this->role,
            'name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'avatar_url' => $this->whenLoaded('user', fn () => $this->user?->avatar_url),
            // Placement and mute are private to their owner.
            'group_id' => $isMe ? $this->group_id : null,
            'sort_order' => $isMe ? $this->sort_order : null,
            'notifications_muted' => $isMe ? $this->notifications_muted : null,
            'joined_at' => Iso::format($this->joined_at),
            'updated_at' => Iso::format($this->updated_at),
            'deleted_at' => Iso::format($this->deleted_at),
        ];
    }
}
