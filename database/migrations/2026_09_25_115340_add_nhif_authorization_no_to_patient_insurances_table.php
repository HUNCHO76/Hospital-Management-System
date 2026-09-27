<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->string('nhif_authorization_no')->nullable()->after('card_number');
        });
    }

    public function down(): void
    {
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->dropColumn('nhif_authorization_no');
        });
    }
};
