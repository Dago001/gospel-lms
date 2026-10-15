<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The template and field layout in force when a certificate was issued, so it always re-renders the way it was issued
        // even after the academy uploads a new template. Null = the built-in design.
        Schema::table('certificates', function (Blueprint $t) {
            $t->string('template_path', 255)->nullable();
            $t->jsonb('layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $t) => $t->dropColumn(['template_path', 'layout']));
    }
};
