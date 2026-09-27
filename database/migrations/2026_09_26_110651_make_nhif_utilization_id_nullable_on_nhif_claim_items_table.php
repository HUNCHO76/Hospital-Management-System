<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_claim_items', function (Blueprint $table) {
            $table->unsignedBigInteger('nhif_utilization_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nhif_claim_items', function (Blueprint $table) {
            $table->unsignedBigInteger('nhif_utilization_id')->nullable(false)->change();
        });
    }
};
