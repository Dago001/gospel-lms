<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $t) {
            $t->boolean('sequential_progression')->default(true); // lessons unlock in order
        });

        Schema::create('video_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider', 20);                       // cloudflare
            $t->string('provider_video_id', 64)->unique();
            $t->string('title', 200);
            $t->string('status', 16)->default('uploading');   // uploading|processing|ready|failed
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->string('error')->nullable();
            $t->foreignUuid('course_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE video_assets ADD CONSTRAINT video_assets_status_valid CHECK (status IN ('uploading','processing','ready','failed'))");
        Schema::table('lessons', fn (Blueprint $t) => $t->foreign('video_asset_id')->references('id')->on('video_assets')->nullOnDelete());

        Schema::create('playback_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('lesson_id')->constrained()->cascadeOnDelete();
            $t->string('state', 10)->default('stopped');       // stopped | playing
            $t->double('last_position')->default(0);
            $t->unsignedBigInteger('last_client_ts_ms')->nullable();
            $t->double('credited_seconds')->default(0);        // total plausible playback credited in this session
            $t->unsignedInteger('last_seq')->default(0);
            $t->timestamp('started_at');
            $t->timestamp('last_event_at')->nullable();
            $t->timestamp('expires_at');                        // absolute cap
            $t->timestamp('ended_at')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'lesson_id']);
        });

        Schema::create('lesson_progress', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('lesson_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->string('status', 12)->default('in_progress');  // in_progress | completed
            $t->jsonb('covered_ranges')->default('[]');        // merged [start,end] seconds of verified playback
            $t->double('covered_seconds')->default(0);
            $t->double('last_position_seconds')->default(0);
            $t->decimal('percent', 5, 2)->default(0);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'lesson_id']);
            $t->index(['course_id', 'user_id']);
        });

        Schema::create('video_progress_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('session_id')->constrained('playback_sessions')->cascadeOnDelete();
            $t->unsignedInteger('seq');
            $t->string('type', 12);
            $t->double('position');
            $t->unsignedBigInteger('client_ts_ms')->nullable();
            $t->boolean('accepted');
            $t->string('reject_reason', 40)->nullable();
            $t->double('credited_seconds')->default(0);
            $t->timestamp('received_at')->useCurrent();
            $t->unique(['session_id', 'seq']);                  // idempotency
        });

        Schema::create('lesson_completion_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('lesson_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->string('method', 16);                           // video | reading | live
            $t->decimal('percent_at_completion', 5, 2)->nullable();
            $t->foreignUuid('playback_session_id')->nullable()->constrained('playback_sessions')->nullOnDelete();
            $t->timestamp('completed_at');
            $t->unique(['user_id', 'lesson_id']);               // a lesson completes once
        });

        Schema::create('resource_acknowledgements', function (Blueprint $t) {
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('resource_id')->constrained('lesson_resources')->cascadeOnDelete();
            $t->timestamp('acknowledged_at');
            $t->primary(['user_id', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_acknowledgements');
        Schema::dropIfExists('lesson_completion_records');
        Schema::dropIfExists('video_progress_events');
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('playback_sessions');
        Schema::table('lessons', fn (Blueprint $t) => $t->dropForeign(['video_asset_id']));
        Schema::dropIfExists('video_assets');
        Schema::table('courses', fn (Blueprint $t) => $t->dropColumn('sequential_progression'));
    }
};
