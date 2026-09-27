<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practitioners', function (Blueprint $table) {
            $table->string('nhif_practitioner_no', 60)->nullable()->unique()->after('consultation_service_id');
        });
    }

    public function down(): void
    {
        Schema::table('practitioners', function (Blueprint $table) {
            $table->dropColumn('nhif_practitioner_no');
        });
    }
};
