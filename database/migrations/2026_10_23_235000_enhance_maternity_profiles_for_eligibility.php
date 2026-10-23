<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('maternity_profiles', 'episode_number')) {
                $table->string('episode_number', 50)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('maternity_profiles', 'clinical_encounter_id')) {
                $table->foreignId('clinical_encounter_id')->nullable()->after('patient_id')->constrained('clinical_encounters')->nullOnDelete();
            }
            if (! Schema::hasColumn('maternity_profiles', 'care_stage')) {
                $table->string('care_stage', 40)->default('awaiting_assessment')->after('labour_stage');
            }
            if (! Schema::hasColumn('maternity_profiles', 'assigned_midwife_id')) {
                $table->foreignId('assigned_midwife_id')->nullable()->after('care_stage')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('maternity_profiles', 'primary_clinician_id')) {
                $table->foreignId('primary_clinician_id')->nullable()->after('assigned_midwife_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('maternity_profiles', 'clinical_exception_reason')) {
                $table->text('clinical_exception_reason')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('maternity_profiles', 'exception_authorized_by')) {
                $table->foreignId('exception_authorized_by')->nullable()->after('clinical_exception_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('maternity_profiles', 'stage_updated_at')) {
                $table->dateTime('stage_updated_at')->nullable()->after('exception_authorized_by');
            }
            if (! Schema::hasColumn('maternity_profiles', 'stage_updated_by')) {
                $table->foreignId('stage_updated_by')->nullable()->after('stage_updated_at')->constrained('users')->nullOnDelete();
            }
        });

        // Make admission_id nullable for outpatient antenatal encounters
        Schema::table('maternity_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('admission_id')->nullable()->change();
        });

        // Generate episode_number for any existing records
        $profiles = DB::table('maternity_profiles')->whereNull('episode_number')->get();
        foreach ($profiles as $profile) {
            $epNumber = 'MAT-' . date('Y') . '-' . str_pad((string) $profile->id, 6, '0', STR_PAD_LEFT);
            DB::table('maternity_profiles')->where('id', $profile->id)->update([
                'episode_number' => $epNumber,
                'care_stage' => 'in_labour',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            $table->dropForeign(['clinical_encounter_id']);
            $table->dropForeign(['assigned_midwife_id']);
            $table->dropForeign(['primary_clinician_id']);
            $table->dropForeign(['exception_authorized_by']);
            $table->dropForeign(['stage_updated_by']);

            $table->dropColumn([
                'episode_number',
                'clinical_encounter_id',
                'care_stage',
                'assigned_midwife_id',
                'primary_clinician_id',
                'clinical_exception_reason',
                'exception_authorized_by',
                'stage_updated_at',
                'stage_updated_by',
            ]);
        });
    }
};
