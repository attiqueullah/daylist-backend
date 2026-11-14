<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->ensureVisible($request, $task);

        return AttachmentResource::collection($task->attachments()->orderBy('created_at')->get());
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->ensureVisible($request, $task);
        $maxKb = config('daylist.max_upload_kb');
        $data = $request->validate([
            'id' => ['sometimes', 'uuid'],
            'file' => ['required', 'file', "max:{$maxKb}"],
        ], [
            'file.max' => 'Attachments can be at most '.round($maxKb / 1024).' MB.',
        ]);

        $user = $request->user();
        if ($existing = $this->existingForReplay(Attachment::class, $data['id'] ?? null, fn ($a) => $a->task?->list?->hasMember($user))) {
            return (new AttachmentResource($existing))->response()->setStatusCode(200);
        }

        $file = $request->file('file');
        $id = $data['id'] ?? (string) Str::uuid();
        $disk = config('daylist.attachments_disk');
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $path = $file->storeAs("attachments/{$task->id}", $id.($extension ? ".{$extension}" : ''), $disk);

        $attachment = Attachment::create([
            'id' => $id,
            'task_id' => $task->id,
            'uploaded_by' => $user->id,
            'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
        ]);
        $task->touch();

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->ensureVisible($request, $attachment->task);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404, 'The file is missing.');

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->file_name, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }

    /** The file stays on disk until the purge job, so a sync race can't lose it. */
    public function destroy(Request $request, Attachment $attachment): Response
    {
        $this->ensureVisible($request, $attachment->task);
        $attachment->delete();

        return response()->noContent();
    }

    private function ensureVisible(Request $request, ?Task $task): void
    {
        abort_unless($task && $task->list && $task->list->hasMember($request->user()), 404);
    }
}
