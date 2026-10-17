<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigram search indexes are an optimisation only. Some hosts do not ship PostgreSQL's contrib extensions, so skip them there
        // (course search then uses plain ILIKE, which is fast enough for a catalogue of this size).
        $trigram = ! \App\Support\DbCompat::mysql() && DB::selectOne("select 1 as ok from pg_available_extensions where name = 'pg_trgm'") !== null;
        if ($trigram) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }

        Schema::create('categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 120)->unique();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('courses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('slug', 160)->unique();
            $t->string('title', 200);
            $t->string('summary', 500)->nullable();
            $t->text('description')->nullable();               // sanitised HTML
            \App\Support\DbCompat::jsonDefault($t->jsonb('objectives'));
            \App\Support\DbCompat::jsonDefault($t->jsonb('requirements'));
            $t->string('level', 20)->default('beginner');       // beginner | intermediate | advanced
            $t->unsignedInteger('duration_minutes_override')->nullable(); // else derived from lessons
            $t->string('thumbnail_path')->nullable();
            // Money: integer minor units (kobo) + explicit currency.
            $t->unsignedBigInteger('price_minor')->default(0);
            $t->char('currency', 3)->default('NGN');
            $t->unsignedBigInteger('discount_price_minor')->nullable();
            $t->timestamp('discount_starts_at')->nullable();
            $t->timestamp('discount_ends_at')->nullable();
            $t->string('status', 20)->default('draft')->index();  // draft|pending_review|approved|published|archived
            $t->text('review_notes')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('scheduled_publish_at')->nullable()->index();
            $t->timestamp('archived_at')->nullable();
            $t->unsignedSmallInteger('video_completion_percent')->default(90); // configurable rule (default 90)
            $t->jsonb('completion_policy')->nullable();         // consumed by the completion engine (Phase 9)
            $t->unsignedInteger('version')->default(1);
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'category_id']);
        });
        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_price_nonneg CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_discount_valid CHECK (discount_price_minor IS NULL OR discount_price_minor <= price_minor)');
        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_video_pct CHECK (video_completion_percent BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_status_valid CHECK (status IN ('draft','pending_review','approved','published','archived'))");
        if ($trigram) {
            DB::statement('CREATE INDEX courses_title_trgm ON courses USING gin (title gin_trgm_ops)');
        }

        Schema::create('course_instructors', function (Blueprint $t) {
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 20)->default('lead'); // lead | assistant
            $t->timestamps();
            $t->primary(['course_id', 'user_id']);
            $t->index('user_id');
        });

        Schema::create('course_prerequisites', function (Blueprint $t) {
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('prerequisite_id')->constrained('courses')->cascadeOnDelete();
            $t->primary(['course_id', 'prerequisite_id']);
        });
        DB::statement('ALTER TABLE course_prerequisites ADD CONSTRAINT course_prereq_not_self CHECK (course_id <> prerequisite_id)');

        Schema::create('course_modules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->unsignedInteger('position');
            $t->timestamps();
            $t->index('course_id');
        });
        DB::statement('ALTER TABLE course_modules ADD CONSTRAINT course_modules_position_unique UNIQUE (course_id, position)'.(\App\Support\DbCompat::mysql() ? '' : ' DEFERRABLE INITIALLY DEFERRED'));

        Schema::create('lessons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('module_id')->constrained('course_modules')->cascadeOnDelete();
            $t->string('title', 200);
            $t->string('type', 20)->default('text');            // text | video | document | live
            $t->longText('content')->nullable();                // sanitised HTML
            $t->string('video_source', 12)->nullable();         // youtube | hosted
            $t->string('youtube_id', 11)->nullable();
            $t->uuid('video_asset_id')->nullable();             // FK added with video_assets (Phase 6)
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->unsignedSmallInteger('required_completion_percent')->nullable(); // per-lesson override of course rule
            $t->boolean('is_required')->default(true);
            $t->boolean('is_preview')->default(false);          // free sample visible without enrolment
            $t->unsignedInteger('position');
            $t->timestamps();
            $t->index(['course_id']);
        });
        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_position_unique UNIQUE (module_id, position)'.(\App\Support\DbCompat::mysql() ? '' : ' DEFERRABLE INITIALLY DEFERRED'));
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_type_valid CHECK (type IN ('text','video','document','live'))");
        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_pct_valid CHECK (required_completion_percent IS NULL OR required_completion_percent BETWEEN 1 AND 100)');

        Schema::create('lesson_resources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('lesson_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->string('path');                 // key in private storage
            $t->string('original_name', 255);
            $t->string('mime', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->boolean('requires_acknowledgement')->default(false);
            $t->timestamps();
        });

        Schema::create('course_enrollments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->restrictOnDelete();   // history is preserved
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('status', 16)->default('active');       // active | suspended | completed | cancelled
            $t->string('source', 16);                           // free | payment | admin
            $t->uuid('order_item_id')->nullable();              // FK added with finance tables (Phase 5)
            $t->timestamp('enrolled_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['course_id', 'user_id']);
            $t->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE course_enrollments ADD CONSTRAINT enrollments_status_valid CHECK (status IN ('active','suspended','completed','cancelled'))");

        Schema::create('course_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->jsonb('snapshot');
            $t->string('note')->nullable();
            $t->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['course_id', 'version']);
        });
    }

    public function down(): void
    {
        foreach (['course_versions','course_enrollments','lesson_resources','lessons','course_modules','course_prerequisites','course_instructors','courses','categories'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
