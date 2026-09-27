<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_member_verifications', function (Blueprint $table) {
            $table->unsignedInteger('nhif_scheme_id')->nullable()->after('nhif_authorization_no');
            $table->string('nhif_facility_code')->nullable()->after('nhif_scheme_id');
        });
    }

    public function down(): void
    {
        Schema::table('nhif_member_verifications', function (Blueprint $table) {
            $table->dropColumn(['nhif_scheme_id', 'nhif_facility_code']);
        });
    }
};
