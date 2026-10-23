<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nhif_member_verifications', function (Blueprint $table) {
            $table->mediumText('member_picture')->nullable()->after('verify_card_response');
        });

        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->mediumText('nhif_member_picture')->nullable()->after('nhif_authorization_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhif_member_verifications', function (Blueprint $table) {
            $table->dropColumn('member_picture');
        });

        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->dropColumn('nhif_member_picture');
        });
    }
};
