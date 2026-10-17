<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('lesson_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->timestamp('starts_at');
            $t->unsignedSmallInteger('duration_minutes');
            $t->string('provider', 10)->default('zoom');            // zoom | manual
            $t->string('provider_meeting_id', 64)->nullable()->unique();
            $t->text('join_url')->nullable();                        // shown only to enrolled learners
            $t->text('host_url')->nullable();                        // staff only
            $t->string('status', 12)->default('scheduled');          // scheduled | live | ended | cancelled
            $t->boolean('counts_for_attendance')->default(true);
            $t->unsignedSmallInteger('min_minutes_for_attendance')->default(0); // 0 = derive 75% of duration
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['course_id', 'starts_at']);
        });

        Schema::create('live_session_attendance', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('live_session_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('minutes_attended')->default(0);
            $t->boolean('present')->default(false);
            $t->string('source', 10);                                 // zoom | manual
            $t->string('note')->nullable();
            $t->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['live_session_id', 'user_id']);
        });

        Schema::create('live_session_recordings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('live_session_id')->constrained()->cascadeOnDelete();
            $t->string('provider_recording_id', 64)->unique();
            $t->text('play_url');                                     // never shown to non-enrolled users
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->boolean('published')->default(false);                 // lecturer must authorise release
            $t->timestamps();
        });

        Schema::create('course_completion_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->boolean('eligible')->default(false);
            $t->decimal('grade_percent', 5, 2)->nullable();
            \App\Support\DbCompat::jsonDefault($t->jsonb('reasons'));                      // why not (empty when eligible)
            \App\Support\DbCompat::jsonDefault($t->jsonb('components'));                   // per-component results used for the decision
            $t->string('trigger', 40)->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('evaluated_at');
            $t->unsignedInteger('evaluations')->default(1);
            $t->timestamps();
            $t->unique(['user_id', 'course_id']);
        });

        Schema::create('certificates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 32)->unique();
            $t->string('verification_code', 40)->unique();            // unguessable, used in the public URL / QR
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $t->string('status', 10)->default('active');              // active | revoked
            $t->string('holder_name', 160);                           // snapshots: a certificate never changes after issue
            $t->string('course_title', 200);
            $t->unsignedInteger('duration_minutes')->nullable();
            $t->decimal('grade_percent', 5, 2)->nullable();
            $t->boolean('show_grade')->default(false);
            $t->string('signatory_name', 160)->nullable();
            $t->string('signatory_title', 160)->nullable();
            $t->timestamp('issued_at');
            $t->timestamps();
            $t->index(['user_id', 'course_id']);
        });
        // At most one ACTIVE certificate per learner per course, even under concurrent completion requests.
        \App\Support\DbCompat::partialUnique('certificates', 'certificates_one_active', ['user_id', 'course_id'], "status = 'active'");
        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_valid CHECK (status IN ('active','revoked'))");

        Schema::create('certificate_revocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('certificate_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reason', 500);
            $t->boolean('block_reissue')->default(true);
            $t->timestamp('revoked_at');
        });
        \App\Support\DbCompat::appendOnly('certificate_revocations');

        Schema::create('certificate_verification_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('certificate_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code_prefix', 8);                             // enough to investigate abuse without storing guessed codes in full
            $t->string('result', 10);                                 // valid | revoked | not_found
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        \App\Support\DbCompat::dropAppendOnly('certificate_revocations');
        foreach (['certificate_verification_logs','certificate_revocations','certificates','course_completion_checks','live_session_recordings','live_session_attendance','live_sessions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
