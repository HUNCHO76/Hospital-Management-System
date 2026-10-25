<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedure_orders', function (Blueprint $table) {
            // Allow outpatient clinical encounter procedures without requiring admission
            $table->unsignedBigInteger('admission_id')->nullable()->change();

            // Financial & Billing Fields
            $table->string('payment_mode', 30)->default('cash')->after('status'); // cash, nhif, insurance, exempt
            $table->string('billing_status', 30)->default('pending_payment')->after('payment_mode'); // pending_payment, paid, pending_authorization, authorized, rejected, exempted
            $table->unsignedInteger('quantity')->default(1)->after('billing_status');
            $table->decimal('unit_price', 12, 2)->default(0.00)->after('quantity');
            $table->decimal('total_price', 12, 2)->default(0.00)->after('unit_price');
            $table->foreignId('charge_id')->nullable()->after('total_price')->constrained('charges')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->after('charge_id')->constrained('invoices')->nullOnDelete();

            // NHIF Authorization Fields
            $table->string('nhif_item_code', 50)->nullable()->after('invoice_id');
            $table->string('nhif_item_name', 255)->nullable()->after('nhif_item_code');
            $table->string('nhif_approval_status', 30)->nullable()->after('nhif_item_name');
            $table->string('nhif_authorization_no', 100)->nullable()->after('nhif_approval_status');
            $table->foreignId('nhif_preauthorization_id')->nullable()->after('nhif_authorization_no')->constrained('nhif_preauthorizations')->nullOnDelete();
            $table->text('nhif_rejection_reason')->nullable()->after('nhif_preauthorization_id');

            // Emergency / Clinical Exemption Fields
            $table->boolean('is_emergency_exemption')->default(false)->after('nhif_rejection_reason');
            $table->text('exemption_reason')->nullable()->after('is_emergency_exemption');
            $table->foreignId('exempted_by')->nullable()->after('exemption_reason')->constrained('users')->nullOnDelete();

            // Procedure Execution & Documentation Fields
            $table->foreignId('performer_id')->nullable()->after('exempted_by')->constrained('users')->nullOnDelete();
            $table->dateTime('performed_at')->nullable()->after('performer_id');
            $table->text('findings')->nullable()->after('performed_at');
            $table->text('results')->nullable()->after('findings');
            $table->text('procedure_notes')->nullable()->after('results');
            $table->text('complications')->nullable()->after('procedure_notes');

            $table->index(['clinical_encounter_id', 'status']);
            $table->index('billing_status');
            $table->index('payment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('procedure_orders', function (Blueprint $table) {
            $table->dropIndex(['clinical_encounter_id', 'status']);
            $table->dropIndex(['billing_status']);
            $table->dropIndex(['payment_mode']);

            $table->dropForeign(['charge_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['nhif_preauthorization_id']);
            $table->dropForeign(['exempted_by']);
            $table->dropForeign(['performer_id']);

            $table->dropColumn([
                'payment_mode',
                'billing_status',
                'quantity',
                'unit_price',
                'total_price',
                'charge_id',
                'invoice_id',
                'nhif_item_code',
                'nhif_item_name',
                'nhif_approval_status',
                'nhif_authorization_no',
                'nhif_preauthorization_id',
                'nhif_rejection_reason',
                'is_emergency_exemption',
                'exemption_reason',
                'exempted_by',
                'performer_id',
                'performed_at',
                'findings',
                'results',
                'procedure_notes',
                'complications',
            ]);
        });
    }
};
