<?php

namespace Tests\Feature\Procurement;

use App\Models\Department;
use App\Models\DepartmentStockRequest;
use App\Models\GoodsReceiptNote;
use App\Models\Hospital;
use App\Models\Indent;
use App\Models\Medication;
use App\Models\ProcurementItem;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Models\StoreTransfer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorQuotation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Hospital $hospital;

    protected Department $department;

    protected Store $mainStore;

    protected Store $pharmacyStore;

    protected User $purchaseOfficer;

    protected User $purchaseManager;

    protected User $storeOfficer;

    protected User $storeManager;

    protected User $admin;

    protected ProcurementItem $paracetamolItem;

    protected Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->hospital = Hospital::create([
            'name' => 'St. Joseph DMI Hospital',
            'code' => 'SJDH',
            'status' => 'active',
        ]);

        $this->department = Department::create([
            'name' => 'Intensive Care Unit (ICU)',
            'code' => 'ICU',
            'hospital_id' => $this->hospital->id,
            'status' => 'active',
        ]);

        $this->mainStore = Store::create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Main Central Store',
            'code' => 'MCS',
            'type' => 'central',
            'status' => 'active',
        ]);

        $this->pharmacyStore = Store::create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Pharmacy Main Store',
            'code' => 'PMS',
            'type' => 'pharmacy',
            'status' => 'active',
        ]);

        $this->purchaseOfficer = User::factory()->create(['UserName' => 'John Officer']);
        $this->purchaseOfficer->assignRole('purchase-officer');

        $this->purchaseManager = User::factory()->create(['UserName' => 'Sarah Manager']);
        $this->purchaseManager->assignRole('purchase-manager');

        $this->storeOfficer = User::factory()->create(['UserName' => 'David Storeman']);
        $this->storeOfficer->assignRole('store-officer');

        $this->storeManager = User::factory()->create(['UserName' => 'Frank StoreManager']);
        $this->storeManager->assignRole('store-manager');

        $this->admin = User::factory()->create(['UserName' => 'Admin User']);
        $this->admin->assignRole('hospital-admin');

        $medication = Medication::create([
            'code' => 'MED-PARA-500',
            'generic_name' => 'Paracetamol',
            'brand_name' => 'Panadol',
            'form' => 'tablet',
            'strength' => '500mg',
            'route' => 'oral',
            'unit' => 'tablet',
            'status' => 'active',
        ]);

        $this->paracetamolItem = ProcurementItem::create([
            'code' => 'MED-PARA-500',
            'name' => 'Paracetamol 500mg Tablets',
            'category' => 'medicines',
            'medication_id' => $medication->id,
            'unit_of_measure' => 'box',
            'estimated_unit_cost' => 5000.00,
            'reorder_level' => 10,
            'minimum_stock' => 5,
            'requires_batch' => true,
            'requires_expiry' => true,
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->vendor = Vendor::create([
            'hospital_id' => $this->hospital->id,
            'code' => 'VEN-MEDPHARM',
            'name' => 'MedPharm Tanzania Ltd',
            'contact_person' => 'Mr. Bakari',
            'email' => 'sales@medpharm.co.tz',
            'phone' => '+255 712 345 678',
            'payment_terms' => 'Net 30 Days',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_indent_lifecycle_creation_submit_and_approval(): void
    {
        // 1. Create Draft Indent
        $response = $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.indents.store'), [
                'department_id' => $this->department->id,
                'target_store_id' => $this->mainStore->id,
                'indent_date' => now()->toDateString(),
                'priority' => 'normal',
                'notes' => 'Emergency ICU refill',
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'item_name' => $this->paracetamolItem->name,
                        'unit_of_measure' => 'box',
                        'quantity_requested' => 10,
                        'estimated_unit_cost' => 5000.00,
                        'purpose' => 'ICU supply refill',
                    ],
                ],
            ]);

        $indent = Indent::where('department_id', $this->department->id)->first();
        $this->assertNotNull($indent);
        $this->assertEquals(Indent::STATUS_DRAFT, $indent->status);
        $this->assertEquals(50000.00, (float) $indent->estimated_total_amount);

        // 2. Submit Indent
        $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.indents.submit', $indent->id));
        $indent->refresh();
        $this->assertEquals(Indent::STATUS_SUBMITTED, $indent->status);

        // 3. Approve Indent by Manager
        $this->actingAs($this->purchaseManager)
            ->post(route('procurement.indents.approve', $indent->id), [
                'comments' => 'Approved for procurement',
                'items' => [
                    [
                        'id' => $indent->items->first()->id,
                        'quantity_approved' => 10,
                    ],
                ],
            ]);
        $indent->refresh();
        $this->assertEquals(Indent::STATUS_APPROVED, $indent->status);
        $this->assertEquals($this->purchaseManager->id, $indent->approved_by);
    }

    public function test_vendor_quotation_creation_and_matrix_comparison(): void
    {
        $indent = Indent::create([
            'hospital_id' => $this->hospital->id,
            'department_id' => $this->department->id,
            'target_store_id' => $this->mainStore->id,
            'indent_number' => 'IND-TEST-001',
            'indent_date' => now()->toDateString(),
            'priority' => 'normal',
            'status' => Indent::STATUS_APPROVED,
            'requested_by' => $this->purchaseOfficer->id,
        ]);

        $response = $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.quotations.store'), [
                'vendor_id' => $this->vendor->id,
                'indent_id' => $indent->id,
                'quotation_date' => now()->toDateString(),
                'lead_time_days' => 5,
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'item_name' => $this->paracetamolItem->name,
                        'quantity' => 10,
                        'unit_of_measure' => 'box',
                        'unit_price' => 4500.00,
                        'discount_rate' => 0,
                        'tax_rate' => 0,
                    ],
                ],
            ]);

        $quotation = VendorQuotation::where('vendor_id', $this->vendor->id)->first();
        $this->assertNotNull($quotation);
        $this->assertEquals(45000.00, (float) $quotation->total_amount);

        // Select winning quote
        $this->actingAs($this->purchaseManager)
            ->post(route('procurement.quotations.select', $quotation->id), [
                'justification' => 'Lowest compliant evaluated tender bid',
            ]);
        $quotation->refresh();
        $this->assertEquals(VendorQuotation::STATUS_SELECTED, $quotation->status);
    }

    public function test_purchase_order_separation_of_duties_enforcement(): void
    {
        // Officer creates PO
        $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.orders.store'), [
                'vendor_id' => $this->vendor->id,
                'receiving_store_id' => $this->mainStore->id,
                'order_date' => now()->toDateString(),
                'terms_conditions' => 'Net 30, DDP',
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'item_name' => $this->paracetamolItem->name,
                        'quantity_ordered' => 20,
                        'unit_of_measure' => 'box',
                        'unit_price' => 4500.00,
                        'tax_rate' => 0,
                    ],
                ],
            ]);

        $po = PurchaseOrder::where('vendor_id', $this->vendor->id)->first();
        $this->assertNotNull($po);
        $this->assertEquals(PurchaseOrder::STATUS_DRAFT, $po->status);

        // Submit PO
        $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.orders.submit', $po->id));
        $po->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_SUBMITTED, $po->status);

        // Give officer the permission so the check reaches service-level separation of duties
        $this->purchaseOfficer->givePermissionTo('procurement-purchase-orders.approve');

        // Separation of Duties: Officer CANNOT approve their own PO
        $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.orders.approve', $po->id))
            ->assertSessionHasErrors(['approver']); // Blocked by separation of duties

        $po->refresh();
        $this->assertNotEquals(PurchaseOrder::STATUS_APPROVED, $po->status);

        // Manager CAN approve
        $this->actingAs($this->purchaseManager)
            ->post(route('procurement.orders.approve', $po->id), [
                'comments' => 'PO verified against hospital procurement budget',
            ]);
        $po->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $po->status);

        // Dispatch PO to Vendor
        $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.orders.send', $po->id));
        $po->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_SENT_TO_VENDOR, $po->status);
    }

    public function test_grn_inspection_and_atomic_stock_posting_without_drift(): void
    {
        // Setup PO
        $po = PurchaseOrder::create([
            'hospital_id' => $this->hospital->id,
            'po_number' => 'PO-TEST-100',
            'vendor_id' => $this->vendor->id,
            'receiving_store_id' => $this->mainStore->id,
            'order_date' => now()->toDateString(),
            'grand_total' => 45000.00,
            'status' => PurchaseOrder::STATUS_SENT_TO_VENDOR,
            'created_by' => $this->purchaseOfficer->id,
        ]);

        $poItem = $po->items()->create([
            'procurement_item_id' => $this->paracetamolItem->id,
            'item_name' => $this->paracetamolItem->name,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_of_measure' => 'box',
            'unit_price' => 4500.00,
            'tax_rate' => 0,
            'total_amount' => 45000.00,
        ]);

        // 1. Create GRN
        $response = $this->actingAs($this->storeOfficer)
            ->post(route('stores.grn.store'), [
                'purchase_order_id' => $po->id,
                'vendor_id' => $this->vendor->id,
                'store_id' => $this->mainStore->id,
                'received_date' => now()->toDateString(),
                'delivery_note_number' => 'DN-9901',
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'purchase_order_item_id' => $poItem->id,
                        'item_name' => $this->paracetamolItem->name,
                        'quantity_delivered' => 10,
                        'unit_of_measure' => 'box',
                        'unit_cost' => 4500.00,
                        'batch_number' => 'PARA-B001',
                        'expiry_date' => now()->addYear()->toDateString(),
                        'storage_location' => 'Bay A-1',
                    ],
                ],
            ]);

        $grn = GoodsReceiptNote::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($grn);
        $this->assertEquals(GoodsReceiptNote::STATUS_INSPECTION_PENDING, $grn->status);

        // 2. Perform Quality Inspection: Accept 8, Reject 2 (damaged items)
        $grnItem = $grn->items()->first();
        $this->actingAs($this->storeManager)
            ->post(route('stores.grn.inspect', $grn->id), [
                'inspection_notes' => 'Two boxes damaged during transit; rejected',
                'items' => [
                    [
                        'id' => $grnItem->id,
                        'quantity_accepted' => 8,
                        'quantity_rejected' => 2,
                        'rejection_reason' => 'Broken vials in transit',
                        'batch_number' => 'PARA-B001',
                        'expiry_date' => now()->addYear()->toDateString(),
                        'storage_location' => 'Bay A-1',
                    ],
                ],
            ]);

        $grn->refresh();
        $this->assertEquals(GoodsReceiptNote::STATUS_PARTIALLY_ACCEPTED, $grn->status);

        // 3. Post to stock ledger
        $this->actingAs($this->storeManager)
            ->post(route('stores.grn.post', $grn->id));

        $grn->refresh();
        $this->assertEquals(GoodsReceiptNote::STATUS_POSTED, $grn->status);

        // Verify atomic inventory balance in StockBatch
        $batch = StockBatch::where('batch_number', 'PARA-B001')->first();
        $this->assertNotNull($batch);
        $this->assertEquals(8, $batch->quantity);
        $this->assertEquals($this->mainStore->id, $batch->store_id);

        // Verify stock transaction log
        $txn = StockTransaction::where('stock_batch_id', $batch->id)->first();
        $this->assertNotNull($txn);
        $this->assertEquals(StockTransaction::TYPE_RECEIPT, $txn->transaction_type);
        $this->assertEquals(8, $txn->quantity);

        // Verify PO received quantity updated
        $poItem->refresh();
        $this->assertEquals(8, $poItem->quantity_received);
    }

    public function test_three_way_matching_detects_price_and_quantity_variances(): void
    {
        $po = PurchaseOrder::create([
            'hospital_id' => $this->hospital->id,
            'po_number' => 'PO-3WAY-001',
            'vendor_id' => $this->vendor->id,
            'receiving_store_id' => $this->mainStore->id,
            'order_date' => now()->toDateString(),
            'grand_total' => 45000.00,
            'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            'created_by' => $this->purchaseOfficer->id,
        ]);

        $poItem = $po->items()->create([
            'procurement_item_id' => $this->paracetamolItem->id,
            'item_name' => $this->paracetamolItem->name,
            'quantity_ordered' => 10,
            'quantity_received' => 8,
            'unit_of_measure' => 'box',
            'unit_price' => 4500.00,
            'tax_rate' => 0,
            'total_amount' => 45000.00,
        ]);

        // GRN has 8 accepted
        $grn = GoodsReceiptNote::create([
            'hospital_id' => $this->hospital->id,
            'grn_number' => 'GRN-3WAY-001',
            'purchase_order_id' => $po->id,
            'vendor_id' => $this->vendor->id,
            'store_id' => $this->mainStore->id,
            'received_date' => now()->toDateString(),
            'status' => GoodsReceiptNote::STATUS_POSTED,
            'received_by' => $this->storeOfficer->id,
        ]);

        $grn->items()->create([
            'procurement_item_id' => $this->paracetamolItem->id,
            'purchase_order_item_id' => $poItem->id,
            'item_name' => $this->paracetamolItem->name,
            'quantity_ordered' => 10,
            'quantity_delivered' => 10,
            'quantity_accepted' => 8,
            'quantity_rejected' => 2,
            'unit_of_measure' => 'box',
            'unit_cost' => 4500.00,
            'total_cost' => 36000.00,
        ]);

        // Vendor sends invoice with unit price 5000 (higher than agreed 4500) AND billed for 10 instead of 8!
        $this->actingAs($this->purchaseOfficer)
            ->post(route('procurement.invoices.store'), [
                'purchase_order_id' => $po->id,
                'goods_receipt_note_id' => $grn->id,
                'vendor_id' => $this->vendor->id,
                'invoice_number' => 'INV-OVERBILL-99',
                'invoice_date' => now()->toDateString(),
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'purchase_order_item_id' => $poItem->id,
                        'goods_receipt_note_item_id' => $grn->items->first()->id,
                        'item_name' => $this->paracetamolItem->name,
                        'quantity' => 10, // 2 more than accepted GRN!
                        'unit_price' => 5000.00, // 500 higher than PO 4500!
                    ],
                ],
            ]);

        $invoice = VendorInvoice::where('invoice_number', 'INV-OVERBILL-99')->first();
        $this->assertNotNull($invoice);
        // Automated 3-way matching MUST flag this invoice as discrepancy!
        $this->assertEquals(VendorInvoice::MATCH_STATUS_DISCREPANCY, $invoice->match_status);
        $this->assertNotNull($invoice->discrepancy_notes);
    }

    public function test_department_stock_request_and_fefo_issue_lifecycle(): void
    {
        // Pre-create stock item and batch in main store
        $stockItem = StockItem::create([
            'hospital_id' => $this->hospital->id,
            'store_id' => $this->mainStore->id,
            'procurement_item_id' => $this->paracetamolItem->id,
            'reorder_level' => 5,
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $batch = StockBatch::create([
            'stock_item_id' => $stockItem->id,
            'store_id' => $this->mainStore->id,
            'batch_number' => 'FEFO-001',
            'quantity' => 50,
            'unit_cost' => 4500.00,
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'status' => StockBatch::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        // 1. Department creates stock request
        $this->storeOfficer->givePermissionTo('stores-requests.create');
        $this->actingAs($this->storeOfficer)
            ->post(route('stores.requests.store'), [
                'department_id' => $this->department->id,
                'from_store_id' => $this->mainStore->id,
                'priority' => 'urgent',
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'item_name' => $this->paracetamolItem->name,
                        'quantity_requested' => 10,
                        'remarks' => 'Emergency ICU floor refill',
                    ],
                ],
            ]);

        $req = DepartmentStockRequest::where('department_id', $this->department->id)->first();
        $this->assertNotNull($req);
        $this->assertEquals(DepartmentStockRequest::STATUS_SUBMITTED, $req->status);

        // 2. Store Manager approves request
        $reqItem = $req->items()->first();
        $this->actingAs($this->storeManager)
            ->post(route('stores.requests.approve', $req->id), [
                'items' => [
                    [
                        'id' => $reqItem->id,
                        'quantity_approved' => 10,
                    ],
                ],
            ]);
        $req->refresh();
        $this->assertEquals(DepartmentStockRequest::STATUS_APPROVED, $req->status);

        // 3. Issue stock to department (FEFO allocation)
        $this->actingAs($this->storeOfficer)
            ->post(route('stores.issues.store'), [
                'department_stock_request_id' => $req->id,
                'from_store_id' => $this->mainStore->id,
                'department_id' => $this->department->id,
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'item_name' => $this->paracetamolItem->name,
                        'quantity_issued' => 10,
                    ],
                ],
            ]);

        $issue = StoreIssue::where('department_stock_request_id', $req->id)->first();
        $this->assertNotNull($issue);
        $this->assertEquals(StoreIssue::STATUS_ISSUED, $issue->status);

        // Verify stock batch was deducted via FEFO
        $batch->refresh();
        $this->assertEquals(40, $batch->quantity);

        // 4. Department confirms receipt
        $this->purchaseOfficer->givePermissionTo('stores-issues.receive');
        $this->actingAs($this->purchaseOfficer)
            ->post(route('stores.issues.receive', $issue->id));
        $issue->refresh();
        $this->assertEquals(StoreIssue::STATUS_RECEIVED, $issue->status);
    }

    public function test_inter_store_transfer_lifecycle(): void
    {
        $stockItem = StockItem::create([
            'hospital_id' => $this->hospital->id,
            'store_id' => $this->mainStore->id,
            'procurement_item_id' => $this->paracetamolItem->id,
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $fromBatch = StockBatch::create([
            'stock_item_id' => $stockItem->id,
            'store_id' => $this->mainStore->id,
            'batch_number' => 'XFER-B1',
            'quantity' => 20,
            'unit_cost' => 4500.00,
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => StockBatch::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        // 1. Create Inter-Store Transfer
        $this->actingAs($this->storeOfficer)
            ->post(route('stores.transfers.store'), [
                'from_store_id' => $this->mainStore->id,
                'to_store_id' => $this->pharmacyStore->id,
                'notes' => 'Transferring stock buffer to pharmacy store',
                'items' => [
                    [
                        'procurement_item_id' => $this->paracetamolItem->id,
                        'from_stock_batch_id' => $fromBatch->id,
                        'item_name' => $this->paracetamolItem->name,
                        'quantity_transferred' => 5,
                    ],
                ],
            ]);

        $transfer = StoreTransfer::where('from_store_id', $this->mainStore->id)->first();
        $this->assertNotNull($transfer);

        // 2. Submit Transfer
        $this->actingAs($this->storeOfficer)
            ->post(route('stores.transfers.submit', $transfer->id));
        $transfer->refresh();
        $this->assertEquals(StoreTransfer::STATUS_SUBMITTED, $transfer->status);

        // 3. Dispatch
        $this->actingAs($this->storeManager)
            ->post(route('stores.transfers.dispatch', $transfer->id));
        $transfer->refresh();
        $this->assertEquals(StoreTransfer::STATUS_IN_TRANSIT, $transfer->status);

        // Source batch deducted
        $fromBatch->refresh();
        $this->assertEquals(15, $fromBatch->quantity);

        // 3. Receive at Destination Store
        $this->actingAs($this->storeManager)
            ->post(route('stores.transfers.receive', $transfer->id));
        $transfer->refresh();
        $this->assertEquals(StoreTransfer::STATUS_RECEIVED, $transfer->status);

        // Destination store batch exists with 5 units
        $destBatch = StockBatch::where('store_id', $this->pharmacyStore->id)
            ->where('batch_number', 'XFER-B1')
            ->first();
        $this->assertNotNull($destBatch);
        $this->assertEquals(5, $destBatch->quantity);
    }

    public function test_procurement_and_stores_reports_endpoints(): void
    {
        $this->actingAs($this->admin)
            ->get(route('stores.reports.stock-valuation'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('stores.reports.expiry'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('stores.reports.reorder-alerts'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('stores.reports.discrepancies'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('stores.reports.department-consumption'))
            ->assertOk();
    }
}
