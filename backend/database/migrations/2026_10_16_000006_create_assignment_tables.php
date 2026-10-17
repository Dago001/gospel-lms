<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_rubrics', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('rubric_criteria', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('rubric_id')->constrained('grading_rubrics')->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->decimal('max_points', 7, 2);
            $t->unsignedSmallInteger('position');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE rubric_criteria ADD CONSTRAINT rubric_points_positive CHECK (max_points > 0)');

        Schema::create('assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('lesson_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('rubric_id')->nullable()->constrained('grading_rubrics')->nullOnDelete();
            $t->string('title', 200);
            $t->text('instructions')->nullable();                 // sanitised HTML
            $t->decimal('max_score', 7, 2);
            $t->decimal('pass_mark', 7, 2)->nullable();
            $t->decimal('weight', 5, 2)->default(0);               // share of course grade (validated by the completion engine)
            $t->boolean('is_required')->default(true);
            $t->timestamp('opens_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->string('late_policy', 16)->default('reject');      // reject | penalty
            $t->decimal('late_penalty_percent', 5, 2)->default(0);
            $t->timestamp('late_cutoff_at')->nullable();
            $t->boolean('allow_resubmission')->default(false);
            $t->unsignedSmallInteger('max_attempts')->default(1);
            $t->unsignedSmallInteger('max_files')->default(3);
            $t->unsignedSmallInteger('max_file_mb')->default(20);
            $t->jsonb('allowed_extensions');
            $t->string('status', 12)->default('draft');            // draft | published
            $t->timestamp('published_at')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['course_id', 'status']);
        });
        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_score_valid CHECK (max_score > 0 AND (pass_mark IS NULL OR (pass_mark >= 0 AND pass_mark <= max_score)))');
        DB::statement("ALTER TABLE assignments ADD CONSTRAINT assignments_policy_valid CHECK (late_policy IN ('reject','penalty') AND late_penalty_percent BETWEEN 0 AND 100)");

        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('assignment_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('attempt');
            $t->text('text_response')->nullable();
            $t->string('status', 16)->default('submitted');         // submitted | graded | returned
            $t->timestamp('submitted_at');
            $t->boolean('is_late')->default(false);
            $t->unsignedInteger('late_minutes')->default(0);
            $t->timestamps();
            $t->unique(['assignment_id', 'user_id', 'attempt']);     // double-submit guard
            $t->index(['assignment_id', 'status']);
        });

        Schema::create('submission_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('submission_id')->constrained('assignment_submissions')->cascadeOnDelete();
            $t->string('path');
            $t->string('original_name', 200);
            $t->string('mime', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->timestamps();
        });

        Schema::create('submission_grades', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('submission_id')->unique()->constrained('assignment_submissions')->restrictOnDelete();
            $t->foreignUuid('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('raw_score', 7, 2);
            $t->decimal('penalty_percent', 5, 2)->default(0);
            $t->decimal('final_score', 7, 2);
            $t->text('feedback')->nullable();
            $t->jsonb('criterion_scores')->nullable();               // {criterion_id: points}
            $t->string('status', 10)->default('draft');              // draft | published
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        DB::statement('ALTER TABLE submission_grades ADD CONSTRAINT grades_scores_nonneg CHECK (raw_score >= 0 AND final_score >= 0 AND final_score <= raw_score)');

        Schema::create('grade_change_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('submission_grade_id')->constrained('submission_grades')->restrictOnDelete();
            $t->decimal('previous_score', 7, 2);
            $t->decimal('new_score', 7, 2);
            $t->text('previous_feedback')->nullable();
            $t->text('new_feedback')->nullable();
            $t->string('reason', 500);
            $t->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        \App\Support\DbCompat::appendOnly('grade_change_logs');
    }

    public function down(): void
    {
        \App\Support\DbCompat::dropAppendOnly('grade_change_logs');
        foreach (['grade_change_logs','submission_grades','submission_files','assignment_submissions','assignments','rubric_criteria','grading_rubrics'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
