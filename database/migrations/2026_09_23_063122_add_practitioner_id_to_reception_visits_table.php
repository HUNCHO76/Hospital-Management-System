<?php

use App\Models\Practitioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reception_visits', function (Blueprint $table) {
            $table->foreignId('practitioner_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reception_visits', function (Blueprint $table) {
            $table->dropForeignIdFor(Practitioner::class, 'practitioner_id');
            $table->dropColumn('practitioner_id');
        });
    }
};
