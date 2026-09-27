<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practitioners', function (Blueprint $table) {
            $table->foreignId('consultation_service_id')->nullable()->after('status')->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('practitioners', function (Blueprint $table) {
            $table->dropForeignIdFor(Service::class, 'consultation_service_id');
            $table->dropColumn('consultation_service_id');
        });
    }
};
