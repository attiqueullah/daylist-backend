<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\ListGroup;
use App\Models\ListMember;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskList;
use App\Support\Iso;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeDeletedRecords extends Command
{
    protected $signature = 'daylist:purge-deleted {--days= : Override the retention in days}';

    protected $description = 'Permanently remove soft-deleted rows (and attachment files) older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('daylist.purge_after_days'));
        $before = Iso::db(Iso::now()->subDays($days));
        $total = 0;

        Attachment::onlyTrashed()->where('deleted_at', '<', $before)->chunkById(200, function ($attachments) use (&$total) {
            foreach ($attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
                $attachment->forceDelete();
                $total++;
            }
        });

        // Children first so foreign keys stay valid.
        foreach ([Step::class, Task::class, ListMember::class, ListGroup::class, TaskList::class] as $model) {
            $count = $model::onlyTrashed()->where('deleted_at', '<', $before)->forceDelete();
            $total += $count;
        }

        $this->info("Purged {$total} record(s) deleted before {$before}.");

        return self::SUCCESS;
    }
}
