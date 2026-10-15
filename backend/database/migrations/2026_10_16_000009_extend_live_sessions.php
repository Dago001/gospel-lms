<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $t) {
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('attendance_synced_at')->nullable();
        });
        Schema::table('live_session_attendance', function (Blueprint $t) {
            $t->string('registrant_id', 64)->nullable();
            $t->text('join_url')->nullable();                        // unique per learner: sharing the link does not share a seat
        });
        Schema::create('sent_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 30);
            $t->uuid('subject_id');
            $t->timestamp('sent_at')->useCurrent();
            $t->unique(['user_id', 'kind', 'subject_id']);           // each reminder is sent once
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_reminders');
        Schema::table('live_session_attendance', fn (Blueprint $t) => $t->dropColumn(['registrant_id', 'join_url']));
        Schema::table('live_sessions', fn (Blueprint $t) => $t->dropColumn(['reminded_at', 'attendance_synced_at']));
    }
};
