<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nhif_item_types')) {
            Schema::create('nhif_item_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('item_type_id')->unique();
                $table->string('type_name');
                $table->string('alias')->nullable();
                $table->string('item_group')->nullable();
                $table->boolean('display_item')->default(true);
                $table->timestamp('nhif_date_created')->nullable();
                $table->timestamp('nhif_last_modified')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->json('raw_data')->nullable();
                $table->timestamps();

                $table->index('item_type_id');
                $table->index('type_name');
            });
        }

        if (Schema::hasTable('nhif_services')) {
            Schema::table('nhif_services', function (Blueprint $table) {
                if (! Schema::hasColumn('nhif_services', 'service_type_id')) {
                    $table->unsignedInteger('service_type_id')->nullable()->after('nhif_item_type_name');
                    $table->index('service_type_id');
                }
                if (! Schema::hasColumn('nhif_services', 'sub_group')) {
                    $table->string('sub_group')->nullable()->after('service_type_id');
                }
                if (! Schema::hasColumn('nhif_services', 'strength')) {
                    $table->string('strength')->nullable()->after('sub_group');
                }
                if (! Schema::hasColumn('nhif_services', 'dosage')) {
                    $table->string('dosage')->nullable()->after('strength');
                }
                if (! Schema::hasColumn('nhif_services', 'calculated_per_day')) {
                    $table->boolean('calculated_per_day')->default(false)->after('dosage');
                }
                if (! Schema::hasColumn('nhif_services', 'is_restricted')) {
                    $table->boolean('is_restricted')->default(false)->after('calculated_per_day');
                    $table->index('is_restricted');
                }
                if (! Schema::hasColumn('nhif_services', 'service_interval')) {
                    $table->integer('service_interval')->default(0)->after('is_restricted');
                }
                if (! Schema::hasColumn('nhif_services', 'type_of_interval')) {
                    $table->string('type_of_interval')->nullable()->after('service_interval');
                }
                if (! Schema::hasColumn('nhif_services', 'waiting_period')) {
                    $table->integer('waiting_period')->default(0)->after('type_of_interval');
                }
                if (! Schema::hasColumn('nhif_services', 'type_of_period')) {
                    $table->string('type_of_period')->nullable()->after('waiting_period');
                }
                if (! Schema::hasColumn('nhif_services', 'eligibility')) {
                    $table->string('eligibility')->nullable()->after('type_of_period');
                }
                if (! Schema::hasColumn('nhif_services', 'common_price')) {
                    $table->decimal('common_price', 12, 2)->nullable()->after('eligibility');
                }
                if (! Schema::hasColumn('nhif_services', 'percent_covered')) {
                    $table->decimal('percent_covered', 5, 2)->nullable()->after('common_price');
                }
                if (! Schema::hasColumn('nhif_services', 'available_in_levels')) {
                    $table->string('available_in_levels')->nullable()->after('percent_covered');
                }
                if (! Schema::hasColumn('nhif_services', 'practitioner_qualifications')) {
                    $table->string('practitioner_qualifications')->nullable()->after('available_in_levels');
                }
                if (! Schema::hasColumn('nhif_services', 'nhif_created_by')) {
                    $table->string('nhif_created_by')->nullable()->after('practitioner_qualifications');
                }
                if (! Schema::hasColumn('nhif_services', 'nhif_date_created')) {
                    $table->timestamp('nhif_date_created')->nullable()->after('nhif_created_by');
                }
                if (! Schema::hasColumn('nhif_services', 'nhif_last_modified_by')) {
                    $table->string('nhif_last_modified_by')->nullable()->after('nhif_date_created');
                }
                if (! Schema::hasColumn('nhif_services', 'nhif_last_modified')) {
                    $table->timestamp('nhif_last_modified')->nullable()->after('nhif_last_modified_by');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_item_types');

        if (Schema::hasTable('nhif_services')) {
            Schema::table('nhif_services', function (Blueprint $table) {
                $columns = [
                    'service_type_id',
                    'sub_group',
                    'strength',
                    'dosage',
                    'calculated_per_day',
                    'is_restricted',
                    'service_interval',
                    'type_of_interval',
                    'waiting_period',
                    'type_of_period',
                    'eligibility',
                    'common_price',
                    'percent_covered',
                    'available_in_levels',
                    'practitioner_qualifications',
                    'nhif_created_by',
                    'nhif_date_created',
                    'nhif_last_modified_by',
                    'nhif_last_modified',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('nhif_services', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
