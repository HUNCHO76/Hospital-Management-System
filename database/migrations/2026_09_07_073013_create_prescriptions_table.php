<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            // unique(): one prescription per medication-type care plan item
            // — a dose change is a correction to this row, not a second one.
            $table->foreignId('care_plan_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('medication_id')->constrained()->restrictOnDelete();
            $table->string('strength')->nullable();
            $table->string('dose')->nullable();
            $table->string('route')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->string('quantity')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
