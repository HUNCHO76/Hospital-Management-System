<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->text('diagnosis_at_discharge')->nullable()->after('discharge_reason');
            $table->string('referred_to_facility_code', 60)->nullable()->after('diagnosis_at_discharge');
        });

        Schema::table('nhif_admissions', function (Blueprint $table) {
            $table->integer('nhif_discharge_type_id')->nullable()->after('nhif_admission_no');
            $table->string('nhif_discharge_status', 30)->nullable()->after('nhif_discharge_type_id');
            $table->unsignedSmallInteger('nhif_discharge_http_status')->nullable()->after('nhif_discharge_status');
            $table->text('nhif_discharge_raw_request')->nullable()->after('nhif_discharge_http_status');
            $table->text('nhif_discharge_raw_response')->nullable()->after('nhif_discharge_raw_request');
            $table->text('nhif_discharge_error')->nullable()->after('nhif_discharge_raw_response');
            $table->timestamp('nhif_discharged_at')->nullable()->after('nhif_discharge_error');
            $table->foreignId('nhif_discharge_submitted_by')->nullable()->after('nhif_discharged_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nhif_admissions', function (Blueprint $table) {
            $table->dropForeign(['nhif_discharge_submitted_by']);
            $table->dropColumn([
                'nhif_discharge_type_id',
                'nhif_discharge_status',
                'nhif_discharge_http_status',
                'nhif_discharge_raw_request',
                'nhif_discharge_raw_response',
                'nhif_discharge_error',
                'nhif_discharged_at',
                'nhif_discharge_submitted_by',
            ]);
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->dropColumn([
                'diagnosis_at_discharge',
                'referred_to_facility_code',
            ]);
        });
    }
};
