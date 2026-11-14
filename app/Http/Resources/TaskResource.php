<?php

namespace App\Http\Resources;

use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'list_id' => $this->list_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'is_completed' => $this->is_completed,
            'completed_at' => Iso::format($this->completed_at),
            'completed_by' => $this->completed_by,
            'is_important' => $this->is_important,
            'due_date' => $this->due_date,
            'reminder_at' => Iso::format($this->reminder_at),
            'recurrence' => $this->recurrence,
            'next_task_id' => $this->next_task_id,
            'my_day_date' => $this->my_day_date,
            'assignee_id' => $this->assignee_id,
            'created_by' => $this->created_by,
            'sort_order' => $this->sort_order,
            'bucket' => $this->when($this->resource->getAttribute('bucket') !== null, fn () => $this->resource->getAttribute('bucket')),
            'steps_count' => $this->whenCounted('steps'),
            'steps' => StepResource::collection($this->whenLoaded('steps')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => Iso::format($this->created_at),
            'updated_at' => Iso::format($this->updated_at),
            'deleted_at' => Iso::format($this->deleted_at),
        ];
    }
}
