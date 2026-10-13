<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Stores (Central store, Pharmacy store, Lab store, Theatre store, Ward stores)
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type')->default('central'); // central, pharmacy, laboratory, radiology, theatre, ward, sub_store
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hospital_id', 'code']);
            $table->index(['hospital_id', 'type', 'status']);
        });

        // 2. Procurement Items Catalog (Medicines, consumables, reagents, radiology, equipment, surgical, general)
        Schema::create('procurement_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category'); // medicine, medical_consumable, laboratory_reagent, radiology_supply, medical_equipment, surgical_theatre, general_supply
            $table->foreignId('medication_id')->nullable()->constrained('medications')->nullOnDelete();
            $table->string('unit_of_measure')->default('piece'); // box, pack, vial, bottle, piece, tablet, kit, roll, ampoule
            $table->text('specification')->nullable();
            $table->unsignedInteger('reorder_level')->default(0);
            $table->unsignedInteger('minimum_stock')->default(0);
            $table->decimal('estimated_unit_cost', 12, 2)->nullable();
            $table->boolean('requires_batch')->default(true);
            $table->boolean('requires_expiry')->default(true);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'status']);
            $table->index('medication_id');
        });

        // 3. Extend stock_items to support stores and procurement_items
        Schema::table('stock_items', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('hospital_id')->constrained('stores')->nullOnDelete();
            $table->foreignId('procurement_item_id')->nullable()->after('medication_id')->constrained('procurement_items')->nullOnDelete();
            // In case a non-medicine item is tracked without medication_id
            $table->unsignedBigInteger('medication_id')->nullable()->change();
        });

        // 4. Vendors Directory
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_id')->nullable(); // TIN / VRN
            $table->string('payment_terms')->nullable(); // Net 30, Net 60, COD, Advance
            $table->text('bank_details')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hospital_id', 'code']);
            $table->index(['hospital_id', 'status']);
        });

        // 5. Extend stock_batches with store_id, vendor_id, and storage_location
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('stock_item_id')->constrained('stores')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->after('supplier_name')->constrained('vendors')->nullOnDelete();
            $table->string('storage_location')->nullable()->after('supplier_name'); // e.g. Shelf A-3, Bin 12
        });

        // 6. Hospital Indents / Purchase Requisitions
        Schema::create('indents', function (Blueprint $table) {
            $table->id();
            $table->string('indent_number')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->date('indent_date')->nullable();
            $table->date('required_by_date')->nullable();
            $table->date('required_date')->nullable();
            $table->string('priority')->default('normal'); // normal, urgent, critical
            $table->string('status')->default('DRAFT'); // DRAFT, SUBMITTED, APPROVED, REJECTED, PARTIALLY_ORDERED, ORDERED, CANCELLED
            $table->text('notes')->nullable();
            $table->text('justification')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index('department_id');
            $table->index('target_store_id');
        });

        Schema::create('indent_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->string('item_name');
            $table->string('unit_of_measure')->nullable();
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_approved')->default(0);
            $table->unsignedInteger('quantity_ordered')->default(0);
            $table->decimal('estimated_unit_cost', 14, 2)->nullable();
            $table->decimal('estimated_total_cost', 14, 2)->nullable();
            $table->text('purpose')->nullable();
            $table->text('specification')->nullable();
            $table->text('specifications')->nullable();
            $table->text('justification')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, ordered
            $table->timestamps();

            $table->index('indent_id');
            $table->index('procurement_item_id');
        });

        // 7. Vendor Quotations & Comparison
        Schema::create('vendor_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indent_id')->nullable()->constrained('indents')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('reference_no')->nullable(); // Vendor's quote #
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->string('delivery_terms')->nullable();
            $table->string('status')->default('draft'); // draft, submitted, selected, rejected
            $table->text('selection_justification')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index('indent_id');
            $table->index('vendor_id');
        });

        Schema::create('vendor_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_quotation_id')->constrained('vendor_quotations')->cascadeOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_rate', 5, 2)->default(0); // percentage
            $table->decimal('tax_rate', 5, 2)->default(0); // percentage
            $table->decimal('total_price', 14, 2);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('vendor_quotation_id');
            $table->index('procurement_item_id');
        });

        // 8. Purchase Orders (PO)
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('receiving_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('indent_id')->nullable()->constrained('indents')->nullOnDelete();
            $table->foreignId('vendor_quotation_id')->nullable()->constrained('vendor_quotations')->nullOnDelete();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('shipping_amount', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->string('status')->default('DRAFT'); // DRAFT, SUBMITTED, APPROVED, SENT_TO_VENDOR, PARTIALLY_RECEIVED, FULLY_RECEIVED, CLOSED, REJECTED, CANCELLED
            $table->text('terms_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index('vendor_id');
            $table->index('receiving_store_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->foreignId('indent_item_id')->nullable()->constrained('indent_items')->nullOnDelete();
            $table->string('item_name');
            $table->string('unit_of_measure');
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->text('specifications')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('procurement_item_id');
        });

        // 9. Goods Receipt Notes (GRN)
        Schema::create('goods_receipt_notes', function (Blueprint $table) {
            $table->id();
            $table->string('grn_number')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->date('received_date');
            $table->string('delivery_note_number')->nullable();
            $table->string('invoice_reference')->nullable();
            $table->string('status')->default('DRAFT'); // DRAFT, RECEIVED, INSPECTION_PENDING, ACCEPTED, PARTIALLY_ACCEPTED, REJECTED, POSTED
            $table->text('inspection_notes')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspection_date')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index('purchase_order_id');
            $table->index('store_id');
            $table->index('vendor_id');
        });

        Schema::create('goods_receipt_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->string('item_name');
            $table->string('unit_of_measure');
            $table->decimal('unit_cost', 14, 2);
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_delivered');
            $table->unsignedInteger('quantity_accepted')->default(0);
            $table->unsignedInteger('quantity_rejected')->default(0);
            $table->unsignedInteger('quantity_short')->default(0);
            $table->string('batch_number')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('storage_location')->nullable();
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_note_id');
            $table->index('purchase_order_item_id');
            $table->index('procurement_item_id');
            $table->index('stock_batch_id');
        });

        // 10. Vendor Invoices (3-Way Matching: PO <-> GRN <-> Vendor Invoice)
        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('system_invoice_number')->unique();
            $table->string('invoice_number'); // Vendor's invoice number
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('goods_receipt_note_id')->nullable()->constrained('goods_receipt_notes')->nullOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('match_status')->default('pending'); // pending, matched, discrepancy
            $table->string('status')->default('DRAFT'); // DRAFT, SUBMITTED, MATCHED, DISCREPANCY, APPROVED, PARTIALLY_PAID, PAID, REJECTED, CANCELLED
            $table->text('discrepancy_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hospital_id', 'vendor_id', 'invoice_number'], 'vendor_invoices_unique_vendor_inv_num');
            $table->index(['hospital_id', 'status']);
            $table->index('purchase_order_id');
            $table->index('match_status');
        });

        Schema::create('vendor_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_invoice_id')->constrained('vendor_invoices')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->foreignId('goods_receipt_note_item_id')->nullable()->constrained('goods_receipt_note_items')->nullOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_price', 14, 2);
            $table->unsignedInteger('matched_po_qty')->default(0);
            $table->unsignedInteger('matched_grn_qty')->default(0);
            $table->decimal('matched_po_price', 14, 2)->default(0);
            $table->boolean('has_discrepancy')->default(false);
            $table->text('discrepancy_reason')->nullable();
            $table->timestamps();

            $table->index('vendor_invoice_id');
            $table->index('purchase_order_item_id');
            $table->index('procurement_item_id');
        });

        // 11. Department Stock Requests (Department -> Store)
        Schema::create('department_stock_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('priority')->default('normal'); // normal, urgent, emergency
            $table->string('status')->default('DRAFT'); // DRAFT, SUBMITTED, APPROVED, PARTIALLY_ISSUED, ISSUED, RECEIVED, REJECTED, CANCELLED
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index('department_id');
            $table->index('from_store_id');
        });

        Schema::create('department_stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_stock_request_id')->constrained('department_stock_requests', indexName: 'dsri_request_fk')->cascadeOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->string('item_name');
            $table->string('unit_of_measure');
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_approved')->default(0);
            $table->unsignedInteger('quantity_issued')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('department_stock_request_id', 'dsri_request_idx');
            $table->index('procurement_item_id', 'dsri_item_idx');
        });

        // 12. Store Issues (Store Outward / Department Distribution)
        Schema::create('store_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_stock_request_id')->nullable()->constrained('department_stock_requests', indexName: 'si_dsr_fk')->nullOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('status')->default('ISSUED'); // ISSUED, RECEIVED, CANCELLED
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index('from_store_id');
            $table->index('department_id');
        });

        Schema::create('store_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_issue_id')->constrained('store_issues')->cascadeOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('item_name');
            $table->string('batch_number');
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('quantity_issued');
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('store_issue_id');
            $table->index('procurement_item_id');
            $table->index('stock_batch_id');
        });

        // 13. Store Transfers (Inter-Store Movement)
        Schema::create('store_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('to_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('status')->default('DRAFT'); // DRAFT, SUBMITTED, APPROVED, IN_TRANSIT, COMPLETED, CANCELLED
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index('from_store_id');
            $table->index('to_store_id');
        });

        Schema::create('store_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_transfer_id')->constrained('store_transfers')->cascadeOnDelete();
            $table->foreignId('procurement_item_id')->constrained('procurement_items')->restrictOnDelete();
            $table->foreignId('from_stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('to_stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->string('item_name');
            $table->string('batch_number');
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('quantity_transferred');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('store_transfer_id');
            $table->index('procurement_item_id');
        });

        // 14. Approval Histories (Unified lifecycle audit for all procurement/store workflows)
        Schema::create('approval_histories', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->string('action'); // submitted, approved, rejected, cancelled, verified, posted
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision')->default('approved');
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['approvable_type', 'approvable_id', 'created_at'], 'appr_hist_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_histories');
        Schema::dropIfExists('store_transfer_items');
        Schema::dropIfExists('store_transfers');
        Schema::dropIfExists('store_issue_items');
        Schema::dropIfExists('store_issues');
        Schema::dropIfExists('department_stock_request_items');
        Schema::dropIfExists('department_stock_requests');
        Schema::dropIfExists('vendor_invoice_items');
        Schema::dropIfExists('vendor_invoices');
        Schema::dropIfExists('goods_receipt_note_items');
        Schema::dropIfExists('goods_receipt_notes');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendor_quotation_items');
        Schema::dropIfExists('vendor_quotations');
        Schema::dropIfExists('indent_items');
        Schema::dropIfExists('indents');

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropForeign(['vendor_id']);
            $table->dropColumn(['store_id', 'vendor_id', 'storage_location']);
        });

        Schema::dropIfExists('vendors');

        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropForeign(['procurement_item_id']);
            $table->dropColumn(['store_id', 'procurement_item_id']);
        });

        Schema::dropIfExists('procurement_items');
        Schema::dropIfExists('stores');
    }
};
