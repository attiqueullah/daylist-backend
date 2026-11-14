<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Core Daylist tables. Every synced table:
 *  - uses a UUID primary key (clients create rows offline with their own ids),
 *  - has created_at / updated_at with microseconds for the sync cursor,
 *  - is soft-deleted (deleted_at) so deletes reach other devices as tombstones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', ['apple', 'google']);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::create('list_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->double('sort_order')->default(0);
            $table->boolean('is_collapsed')->default(false);
            $table->datetimes(6);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('task_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable so that deleting an account can leave a tombstone
            // behind for the members of lists it owned.
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('emoji', 16)->nullable();
            $table->string('theme_color', 32)->nullable();
            $table->string('theme_image')->nullable();
            $table->enum('sort_mode', ['manual', 'importance', 'due_date', 'alphabetical', 'created_at', 'my_day'])->default('manual');
            $table->enum('sort_direction', ['asc', 'desc'])->default('asc');
            $table->boolean('show_completed')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_shared')->default(false);
            $table->boolean('sharing_limited')->default(false);
            $table->datetimes(6);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->index('updated_at');
        });

        Schema::create('list_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('task_lists')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'member'])->default('member');
            $table->foreignUuid('group_id')->nullable()->constrained('list_groups')->nullOnDelete();
            $table->double('sort_order')->default(0);
            $table->boolean('notifications_muted')->default(false);
            $table->dateTime('joined_at', 6);
            $table->datetimes(6);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->unique(['list_id', 'user_id']);
            $table->index(['user_id', 'updated_at']);
            $table->index('updated_at');
        });

        Schema::create('share_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('task_lists')->cascadeOnDelete();
            $table->char('token', 43)->unique();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('task_lists')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 500);
            $table->text('notes')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->dateTime('completed_at', 6)->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_important')->default(false);
            $table->date('due_date')->nullable();
            $table->dateTime('reminder_at')->nullable();
            $table->string('recurrence')->nullable();
            $table->uuid('next_task_id')->nullable();
            $table->date('my_day_date')->nullable();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->double('sort_order')->default(0);
            $table->datetimes(6);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->index(['list_id', 'is_completed']);
            $table->index('my_day_date');
            $table->index('due_date');
            $table->index('updated_at');
        });

        Schema::create('steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->string('title', 500);
            $table->boolean('is_completed')->default(false);
            $table->double('sort_order')->default(0);
            $table->datetimes(6);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->index('updated_at');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->datetimes(6);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('steps');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('share_invites');
        Schema::dropIfExists('list_members');
        Schema::dropIfExists('task_lists');
        Schema::dropIfExists('list_groups');
        Schema::dropIfExists('social_accounts');
    }
};
