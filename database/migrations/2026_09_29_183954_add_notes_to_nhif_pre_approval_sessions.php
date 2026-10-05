<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_pre_approval_sessions', function (Blueprint $table): void {
            $table->text('clinical_notes')->nullable()->after('practitioner_no');
            $table->text('practitioner_remarks')->nullable()->after('clinical_notes');
        });
    }

    public function down(): void
    {
        Schema::table('nhif_pre_approval_sessions', function (Blueprint $table): void {
            $table->dropColumn(['clinical_notes', 'practitioner_remarks']);
        });
    }
};
