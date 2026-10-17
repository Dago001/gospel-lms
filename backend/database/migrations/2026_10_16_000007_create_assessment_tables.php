<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('questions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('bank_id')->constrained('question_banks')->cascadeOnDelete();
            $t->string('type', 10);                                 // mcq | multi | tf | short | essay
            $t->string('category', 80)->nullable();
            $t->string('difficulty', 8)->default('medium');         // easy | medium | hard
            $t->text('prompt');                                      // sanitised HTML
            $t->decimal('points', 6, 2)->default(1);
            $t->text('explanation')->nullable();                     // shown only per the assessment's review rule
            $t->jsonb('accepted_answers')->nullable();              // short answer
            $t->boolean('case_sensitive')->default(false);
            $t->boolean('partial_credit')->default(false);          // multi-select
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['bank_id', 'category', 'difficulty']);
        });
        DB::statement("ALTER TABLE questions ADD CONSTRAINT questions_type_valid CHECK (type IN ('mcq','multi','tf','short','essay'))");
        DB::statement("ALTER TABLE questions ADD CONSTRAINT questions_difficulty_valid CHECK (difficulty IN ('easy','medium','hard'))");
        DB::statement('ALTER TABLE questions ADD CONSTRAINT questions_points_positive CHECK (points > 0)');

        Schema::create('question_options', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('question_id')->constrained()->cascadeOnDelete();
            $t->string('label', 1000);
            $t->boolean('is_correct')->default(false);
            $t->unsignedSmallInteger('position');
            $t->timestamps();
        });

        Schema::create('assessments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->uuid('parent_assessment_id')->nullable(); // supplementary -> original (FK added below: self-reference)
            $t->string('kind', 14)->default('quiz');                 // quiz | exam | supplementary
            $t->string('title', 200);
            $t->text('instructions')->nullable();
            $t->string('status', 10)->default('draft');              // draft | published | archived
            $t->unsignedInteger('version')->default(1);
            $t->unsignedSmallInteger('time_limit_minutes')->nullable();
            $t->timestamp('opens_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->unsignedSmallInteger('max_attempts')->default(1);
            $t->decimal('pass_mark_percent', 5, 2)->default(50);
            $t->decimal('weight', 5, 2)->default(0);
            $t->boolean('is_required')->default(true);
            $t->boolean('is_final_exam')->default(false);            // mandatory independent pass (Phase 10)
            $t->boolean('require_all_lessons')->default(false);
            $t->boolean('shuffle_questions')->default(false);
            $t->boolean('shuffle_options')->default(false);
            $t->string('results_release', 10)->default('immediate'); // immediate | manual
            $t->string('review_policy', 12)->default('never');       // never | after_close | immediate  (show correct answers)
            $t->jsonb('pools')->nullable();                           // [{bank_id,count,category?,difficulty?}]
            $t->timestamp('published_at')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['course_id', 'status']);
        });
        Schema::table('assessments', fn (Blueprint $t) => $t->foreign('parent_assessment_id')->references('id')->on('assessments')->nullOnDelete());
        DB::statement("ALTER TABLE assessments ADD CONSTRAINT assessments_kind_valid CHECK (kind IN ('quiz','exam','supplementary'))");
        DB::statement('ALTER TABLE assessments ADD CONSTRAINT assessments_pass_valid CHECK (pass_mark_percent BETWEEN 0 AND 100 AND weight BETWEEN 0 AND 100)');

        Schema::create('assessment_questions', function (Blueprint $t) {
            $t->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('question_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('position');
            $t->decimal('points', 6, 2)->nullable();                 // override of the question's points
            $t->primary(['assessment_id', 'question_id']);
        });

        Schema::create('assessment_attempt_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('extra_attempts');
            $t->string('reason', 255);
            $t->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('assessment_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('assessment_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('assessment_version');
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('attempt_no');
            $t->string('status', 12)->default('in_progress');        // in_progress | grading | graded
            $t->timestamp('started_at');
            $t->timestamp('deadline_at')->nullable();                 // server-authoritative end time
            $t->timestamp('submitted_at')->nullable();
            $t->boolean('auto_submitted')->default(false);
            $t->jsonb('question_set');                                // frozen snapshot of exactly what this attempt asked
            $t->decimal('max_score', 8, 2);
            $t->decimal('total_score', 8, 2)->nullable();
            $t->decimal('percent', 5, 2)->nullable();
            $t->boolean('passed')->nullable();
            $t->timestamp('graded_at')->nullable();
            $t->timestamp('results_published_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['assessment_id', 'user_id', 'attempt_no']);   // attempt-limit race guard
            $t->index(['status', 'deadline_at']);
        });
        // One live attempt per learner per assessment.
        \App\Support\DbCompat::partialUnique('assessment_attempts', 'attempts_one_in_progress', ['assessment_id', 'user_id'], "status = 'in_progress'");

        Schema::create('assessment_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $t->uuid('question_id');
            $t->jsonb('answer')->nullable();                          // {option_ids:[]} | {text:""}
            $t->boolean('is_correct')->nullable();
            $t->decimal('auto_points', 6, 2)->nullable();
            $t->decimal('manual_points', 6, 2)->nullable();
            $t->text('feedback')->nullable();
            $t->foreignUuid('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('saved_at');
            $t->timestamps();
            $t->unique(['attempt_id', 'question_id']);                // incremental saves are upserts
        });
    }

    public function down(): void
    {
        foreach (['assessment_responses','assessment_attempts','assessment_attempt_grants','assessment_questions','assessments','question_options','questions','question_banks'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
