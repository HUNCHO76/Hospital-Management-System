<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reception_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('reception_visits', 'visit_purpose')) {
                $table->string('visit_purpose', 50)->nullable()->after('visit_type');
            }
            if (! Schema::hasColumn('reception_visits', 'maternity_status')) {
                $table->string('maternity_status', 40)->nullable()->after('status');
                $table->index('maternity_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reception_visits', function (Blueprint $table) {
            if (Schema::hasColumn('reception_visits', 'visit_purpose')) {
                $table->dropColumn('visit_purpose');
            }
            if (Schema::hasColumn('reception_visits', 'maternity_status')) {
                $table->dropIndex(['maternity_status']);
                $table->dropColumn('maternity_status');
            }
        });
    }
};
