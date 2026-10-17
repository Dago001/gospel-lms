<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 40)->unique();
            $t->string('name', 80);
            $t->string('description')->nullable();
            $t->boolean('is_privileged')->default(false); // privileged roles must use MFA
            $t->boolean('is_system')->default(true);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 80)->unique();
            $t->string('name', 120);
            $t->string('group', 40)->index();
            $t->timestamps();
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->uuid('role_id');
            $t->uuid('user_id');
            $t->uuid('assigned_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->primary(['role_id', 'user_id']);
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index('user_id');
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->uuid('permission_id');
            $t->uuid('role_id');
            $t->primary(['permission_id', 'role_id']);
            $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('user_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();
            $t->string('phone', 32)->nullable();
            $t->text('bio')->nullable();
            $t->string('country', 2)->nullable();
            $t->string('photo_path')->nullable(); // private object storage key
            $t->string('timezone', 64)->default('Africa/Lagos');
            $t->string('headline')->nullable();   // lecturers: public title
            $t->boolean('public_profile')->default(false);
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('user_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('session_id', 255)->nullable()->index(); // Laravel session id at login time
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('last_active_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['user_id', 'revoked_at']);
        });

        Schema::create('two_factor_authentications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();
            $t->text('secret');                 // encrypted TOTP secret
            $t->text('recovery_codes');         // encrypted JSON of sha256-hashed codes
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('last_used_step')->nullable(); // replay protection
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('actor_id')->nullable()->index();
            $t->string('action', 100)->index();
            $t->string('subject_type', 100)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->jsonb('old_values')->nullable();
            $t->jsonb('new_values')->nullable();
            $t->string('reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
        });
        // Audit logs are append-only: block UPDATE and DELETE at the database level.
        \App\Support\DbCompat::appendOnly('audit_logs');

        Schema::create('consent_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('type', 40);       // terms | privacy | marketing
            $t->string('version', 20);
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('granted_at');
            $t->timestamp('withdrawn_at')->nullable();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        \App\Support\DbCompat::dropAppendOnly('audit_logs');
        foreach (['consent_records','audit_logs','two_factor_authentications','user_sessions','user_profiles','permission_role','role_user','permissions','roles'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
