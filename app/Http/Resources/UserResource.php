<?php

namespace App\Http\Resources;

use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in user (full) or another person on a shared list (public fields only). */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isMe = $request->user()?->id === $this->id;

        return array_merge([
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
        ], $isMe ? [
            'email' => $this->email,
            'timezone' => $this->timezone,
            'settings' => $this->resolvedSettings(),
            'providers' => $this->socialAccounts()->pluck('provider'),
            'has_password' => $this->password !== null,
            'created_at' => Iso::format($this->created_at),
        ] : []);
    }
}
