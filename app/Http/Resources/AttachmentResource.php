<?php

namespace App\Http\Resources;

use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by' => $this->uploaded_by,
            'download_url' => $this->deleted_at ? null : url("/api/v1/attachments/{$this->id}/download"),
            'created_at' => Iso::format($this->created_at),
            'updated_at' => Iso::format($this->updated_at),
            'deleted_at' => Iso::format($this->deleted_at),
        ];
    }
}
