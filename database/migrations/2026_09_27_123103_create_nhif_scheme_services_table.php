<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_scheme_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('scheme_id')->index();
            $table->string('scheme_name')->nullable();
            $table->string('item_code')->index();
            $table->string('item_name');
            $table->string('item_type_id')->nullable();
            $table->string('type_name')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('dosage')->nullable();
            $table->string('strength')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['scheme_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_scheme_services');
    }
};
