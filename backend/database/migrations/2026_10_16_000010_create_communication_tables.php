<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);
            $t->boolean('email')->default(true);
            $t->boolean('in_app')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'type']);
        });

        Schema::create('notification_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('notification', 80);
            $t->string('channel', 20);
            $t->string('status', 12);                    // sent | failed | suppressed
            $t->text('error')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['status', 'created_at']);
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->nullable()->constrained()->cascadeOnDelete();   // null = platform-wide
            $t->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title', 200);
            $t->text('body');                              // sanitised HTML
            $t->string('audience', 10)->default('all');    // platform-wide only: all | students | lecturers
            $t->boolean('pinned')->default(false);
            $t->timestamp('publish_at');
            $t->timestamp('expires_at')->nullable();
            $t->boolean('notify')->default(false);
            $t->timestamp('notified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['course_id', 'publish_at']);
        });

        Schema::create('discussion_threads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('title', 200);
            $t->text('body');
            $t->boolean('pinned')->default(false);
            $t->boolean('locked')->default(false);
            $t->timestamp('last_post_at');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['course_id', 'pinned', 'last_post_at']);
        });

        Schema::create('discussion_posts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('thread_id')->constrained('discussion_threads')->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->text('body');
            $t->timestamp('edited_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['thread_id', 'created_at']);
        });

        Schema::create('support_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 16)->unique();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name', 120)->nullable();
            $t->string('guest_email', 190)->nullable();
            $t->foreignUuid('course_id')->nullable()->constrained()->nullOnDelete();
            $t->string('subject', 200);
            $t->string('category', 12)->default('other');
            $t->string('status', 10)->default('open');      // open | pending | resolved | closed
            $t->string('priority', 8)->default('normal');   // low | normal | high
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_activity_at');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'last_activity_at']);
        });
        DB::statement('ALTER TABLE support_tickets ADD CONSTRAINT support_requester CHECK (user_id IS NOT NULL OR guest_email IS NOT NULL)');

        Schema::create('support_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('author_type', 8);                    // user | staff | guest
            $t->text('body');
            $t->boolean('is_internal')->default(false);     // staff-only note
            $t->timestamps();
            $t->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['support_messages', 'support_tickets', 'discussion_posts', 'discussion_threads', 'announcements', 'notification_logs', 'notification_preferences'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
