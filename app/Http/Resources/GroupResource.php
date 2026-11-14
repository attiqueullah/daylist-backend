<?php

namespace App\Http\Resources;

use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_collapsed' => $this->is_collapsed,
            'created_at' => Iso::format($this->created_at),
            'updated_at' => Iso::format($this->updated_at),
            'deleted_at' => Iso::format($this->deleted_at),
        ];
    }
}
