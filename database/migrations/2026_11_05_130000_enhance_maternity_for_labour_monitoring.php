<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enhances maternity profiles and partograph entries for Labour Monitoring (Phase 5):
     * - Labour onset information (date/time, spontaneous vs induced vs augmented)
     * - Cervical effacement percentage
     * - Reception visit linkage for outpatient labour assessments
     */
    public function up(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('maternity_profiles', 'labour_onset_at')) {
                $table->dateTime('labour_onset_at')->nullable()->after('labour_stage');
            }
            if (! Schema::hasColumn('maternity_profiles', 'labour_onset_type')) {
                $table->string('labour_onset_type', 30)->default('spontaneous')->nullable()->after('labour_onset_at');
            }
            if (! Schema::hasColumn('maternity_profiles', 'cervix_effacement_pct')) {
                $table->unsignedTinyInteger('cervix_effacement_pct')->nullable()->after('labour_onset_type');
            }
        });

        Schema::table('partograph_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('admission_id')->nullable()->change();

            if (! Schema::hasColumn('partograph_entries', 'reception_visit_id')) {
                $table->foreignId('reception_visit_id')
                    ->nullable()
                    ->after('clinical_encounter_id')
                    ->constrained('reception_visits')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partograph_entries', function (Blueprint $table) {
            if (Schema::hasColumn('partograph_entries', 'reception_visit_id')) {
                $table->dropConstrainedForeignId('reception_visit_id');
            }
        });

        Schema::table('maternity_profiles', function (Blueprint $table) {
            $columns = [];
            foreach (['labour_onset_at', 'labour_onset_type', 'cervix_effacement_pct'] as $col) {
                if (Schema::hasColumn('maternity_profiles', $col)) {
                    $columns[] = $col;
                }
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
