<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('maternity_profiles', 'care_pathway')) {
                $table->string('care_pathway', 30)->default('inpatient')->after('care_stage');
            }
        });

        Schema::table('partograph_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('admission_id')->nullable()->change();

            if (! Schema::hasColumn('partograph_entries', 'clinical_encounter_id')) {
                $table->foreignId('clinical_encounter_id')->nullable()->after('admission_id')->constrained('clinical_encounters')->nullOnDelete();
            }
            if (! Schema::hasColumn('partograph_entries', 'is_emergency')) {
                $table->boolean('is_emergency')->default(false)->after('notes');
            }
            if (! Schema::hasColumn('partograph_entries', 'emergency_reason')) {
                $table->text('emergency_reason')->nullable()->after('is_emergency');
            }
            if (! Schema::hasColumn('partograph_entries', 'emergency_authorized_by')) {
                $table->foreignId('emergency_authorized_by')->nullable()->after('emergency_reason')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('delivery_records', function (Blueprint $table) {
            $table->unsignedBigInteger('admission_id')->nullable()->change();

            if (! Schema::hasColumn('delivery_records', 'clinical_encounter_id')) {
                $table->foreignId('clinical_encounter_id')->nullable()->after('admission_id')->constrained('clinical_encounters')->nullOnDelete();
            }
            if (! Schema::hasColumn('delivery_records', 'is_emergency')) {
                $table->boolean('is_emergency')->default(false)->after('resuscitation_notes');
            }
            if (! Schema::hasColumn('delivery_records', 'emergency_reason')) {
                $table->text('emergency_reason')->nullable()->after('is_emergency');
            }
            if (! Schema::hasColumn('delivery_records', 'emergency_authorized_by')) {
                $table->foreignId('emergency_authorized_by')->nullable()->after('emergency_reason')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('maternity_profiles', 'care_pathway')) {
                $table->dropColumn('care_pathway');
            }
        });

        Schema::table('partograph_entries', function (Blueprint $table) {
            if (Schema::hasColumn('partograph_entries', 'clinical_encounter_id')) {
                $table->dropConstrainedForeignId('clinical_encounter_id');
            }
            if (Schema::hasColumn('partograph_entries', 'emergency_authorized_by')) {
                $table->dropConstrainedForeignId('emergency_authorized_by');
            }
            if (Schema::hasColumn('partograph_entries', 'emergency_reason')) {
                $table->dropColumn(['is_emergency', 'emergency_reason']);
            }
        });

        Schema::table('delivery_records', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_records', 'clinical_encounter_id')) {
                $table->dropConstrainedForeignId('clinical_encounter_id');
            }
            if (Schema::hasColumn('delivery_records', 'emergency_authorized_by')) {
                $table->dropConstrainedForeignId('emergency_authorized_by');
            }
            if (Schema::hasColumn('delivery_records', 'emergency_reason')) {
                $table->dropColumn(['is_emergency', 'emergency_reason']);
            }
        });
    }
};
