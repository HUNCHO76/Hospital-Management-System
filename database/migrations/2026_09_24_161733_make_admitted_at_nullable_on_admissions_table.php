<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 71 fix: admitted_at must be nullable because a PENDING admission
 * request hasn't been admitted yet — the timestamp is only set inside
 * AdmissionService::confirm().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->timestamp('admitted_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->timestamp('admitted_at')->nullable(false)->change();
        });
    }
};
