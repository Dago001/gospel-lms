<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->jsonb('value')->nullable();
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('data_export_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 12)->default('requested');      // requested | ready | failed | expired
            $t->string('file_path')->nullable();                  // private storage
            $t->timestamp('requested_at');
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'requested_at']);
        });

        Schema::create('backup_audit_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 14);                               // backup | restore_test
            $t->string('status', 10);                             // ok | failed
            $t->string('location')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->jsonb('details')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['type', 'started_at']);
        });
    }

    public function down(): void
    {
        foreach (['backup_audit_records', 'data_export_requests', 'system_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
