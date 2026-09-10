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
        Schema::create('practitioners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('practitioner_number')->unique();
            $table->string('practitioner_type');
            $table->string('professional_registration_number')->nullable();
            $table->string('specialization')->nullable();
            $table->string('license_number')->nullable();
            $table->date('license_expiry_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('practitioner_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practitioners');
    }
};
