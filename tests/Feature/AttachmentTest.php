<?php

namespace Tests\Feature;

use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_upload_list_download_delete(): void
    {
        $user = $this->signIn();
        $task = $this->makeTask($this->makeList($user));

        $attachment = $this->postJson("/api/v1/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('receipt.pdf', 300, 'application/pdf'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.file_name', 'receipt.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size_bytes', 300 * 1024)
            ->assertJsonMissingPath('data.path')
            ->json('data');

        $stored = Attachment::find($attachment['id']);
        Storage::disk('local')->assertExists($stored->path);

        $this->getJson("/api/v1/tasks/{$task->id}/attachments")->assertJsonCount(1, 'data');

        $this->get("/api/v1/attachments/{$attachment['id']}/download")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=receipt.pdf');

        $this->deleteJson("/api/v1/attachments/{$attachment['id']}")->assertNoContent();
        $this->assertSoftDeleted($stored);
        $this->get("/api/v1/attachments/{$attachment['id']}/download")->assertNotFound();
    }

    public function test_25_mb_limit(): void
    {
        $user = $this->signIn();
        $task = $this->makeTask($this->makeList($user));

        $this->postJson("/api/v1/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->create('ok.mov', 25 * 1024)])
            ->assertCreated();

        $this->postJson("/api/v1/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->create('big.mov', 25 * 1024 + 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Attachments can be at most 25 MB.']);

        $this->postJson("/api/v1/tasks/{$task->id}/attachments", [])->assertJsonValidationErrors('file');
    }

    public function test_only_list_members_can_reach_files(): void
    {
        $owner = $this->signIn();
        $list = $this->makeList($owner);
        $task = $this->makeTask($list);
        $id = $this->postJson("/api/v1/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->image('photo.jpg')])->json('data.id');

        $member = $this->makeUser();
        $this->share($list, $member);
        Sanctum::actingAs($member);
        $this->get("/api/v1/attachments/{$id}/download")->assertOk();

        Sanctum::actingAs($this->makeUser());
        $this->get("/api/v1/attachments/{$id}/download")->assertNotFound();
        $this->deleteJson("/api/v1/attachments/{$id}")->assertNotFound();
        $this->postJson("/api/v1/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
    }
}
