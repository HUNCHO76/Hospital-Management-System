<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_pre_approval_session_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nhif_pre_approval_session_id');
            $table->foreign('nhif_pre_approval_session_id', 'nhif_pas_items_session_fk')
                ->references('id')
                ->on('nhif_pre_approval_sessions')
                ->cascadeOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('nhif_item_code');
            $table->string('nhif_item_name')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->string('approval_reference_no')->nullable();
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'issued'])->default('pending');
            $table->unsignedInteger('quantity_approved')->nullable();
            $table->unsignedInteger('quantity_issued')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_pre_approval_session_items');
    }
};
