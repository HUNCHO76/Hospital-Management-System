<?php

namespace Database\Seeders;

use App\Models\ApprovalHistory;
use App\Models\Department;
use App\Models\DepartmentStockRequest;
use App\Models\DepartmentStockRequestItem;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\Hospital;
use App\Models\Indent;
use App\Models\IndentItem;
use App\Models\Medication;
use App\Models\ProcurementItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Models\StoreIssueItem;
use App\Models\StoreTransfer;
use App\Models\StoreTransferItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceItem;
use App\Models\VendorQuotation;
use App\Models\VendorQuotationItem;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProcurementSeeder extends Seeder
{
    public function run(): void
    {
        // -------------------------------------------------------------
        // 1. Resolve Users & Core Hospital
        // -------------------------------------------------------------
        $hospital = Hospital::first() ?? Hospital::create([
            'code' => 'MAIN',
            'name' => 'Main Hospital',
            'facility_code' => '01141',
            'status' => 'active',
        ]);

        $purchaseOfficer = User::where('email', 'purchaseofficer@hms.test')->first()
            ?? User::where('email', 'peter.kimaro@purchase-officer.test')->first()
            ?? User::first();

        $purchaseManager = User::where('email', 'purchasemanager@hms.test')->first()
            ?? User::where('email', 'patricia.lyimo@purchase-manager.test')->first()
            ?? $purchaseOfficer;

        $storeOfficer = User::where('email', 'storeofficer@hms.test')->first()
            ?? User::where('email', 'salim.bakari@store-officer.test')->first()
            ?? $purchaseOfficer;

        $storeManager = User::where('email', 'storemanager@hms.test')->first()
            ?? User::where('email', 'stella.mrema@store-manager.test')->first()
            ?? $storeOfficer;

        $deptUser = User::where('email', 'deptuser@hms.test')->first()
            ?? User::where('email', 'dennis.shirima@department-user.test')->first()
            ?? $purchaseOfficer;

        $pharmacist = User::where('email', 'pharmacist@hms.test')->first()
            ?? User::where('email', 'catherine.mwakalinga@pharmacist.test')->first()
            ?? $deptUser;

        $labTech = User::where('email', 'labtech@hms.test')->first()
            ?? User::where('email', 'david.kessy@laboratory-technician.test')->first()
            ?? $deptUser;

        $nurse = User::where('email', 'nurse@hms.test')->first()
            ?? User::where('email', 'baraka.mushi@nurse.test')->first()
            ?? $deptUser;

        $accountant = User::where('email', 'accountant@hms.test')->first()
            ?? User::where('email', 'frank.ngowi@accountant.test')->first()
            ?? $purchaseManager;

        // -------------------------------------------------------------
        // 2. Global Procurement Catalog Items (Medicines, Consumables, Reagents, etc.)
        // -------------------------------------------------------------
        $medications = Medication::where('status', 'active')->limit(30)->get();
        foreach ($medications as $med) {
            $medName = $med->brand_name ? "{$med->generic_name} ({$med->brand_name})" : ($med->generic_name ?? "Medication {$med->code}");
            ProcurementItem::firstOrCreate(
                ['code' => 'MED-'.str_pad((string) $med->id, 5, '0', STR_PAD_LEFT)],
                [
                    'name' => $medName,
                    'category' => ProcurementItem::CATEGORY_MEDICINE,
                    'medication_id' => $med->id,
                    'unit_of_measure' => $med->unit ?? $med->form ?? 'tablet',
                    'specification' => ($med->generic_name ?? '').' '.($med->strength ?? ''),
                    'reorder_level' => 100,
                    'minimum_stock' => 50,
                    'estimated_unit_cost' => $med->price ?? 500.00,
                    'requires_batch' => true,
                    'requires_expiry' => true,
                    'status' => 'active',
                ]
            );
        }

        $nonMeds = [
            [
                'code' => 'CON-001',
                'name' => 'Examination Gloves Latex (Medium)',
                'category' => ProcurementItem::CATEGORY_CONSUMABLE,
                'unit_of_measure' => 'box',
                'specification' => 'Powder-free, box of 100 pcs',
                'reorder_level' => 25,
                'minimum_stock' => 10,
                'estimated_unit_cost' => 12000.00,
            ],
            [
                'code' => 'CON-002',
                'name' => 'Syringes Disposable 5ml with Needle 21G',
                'category' => ProcurementItem::CATEGORY_CONSUMABLE,
                'unit_of_measure' => 'box',
                'specification' => 'Sterile disposable 5ml syringes with needle, 100 pcs',
                'reorder_level' => 20,
                'minimum_stock' => 10,
                'estimated_unit_cost' => 15000.00,
            ],
            [
                'code' => 'CON-003',
                'name' => 'IV Cannula 20G Pink with Injection Port',
                'category' => ProcurementItem::CATEGORY_CONSUMABLE,
                'unit_of_measure' => 'box',
                'specification' => 'Sterile intravenous cannula with wings and port, 50 pcs',
                'reorder_level' => 30,
                'minimum_stock' => 15,
                'estimated_unit_cost' => 22000.00,
            ],
            [
                'code' => 'REA-001',
                'name' => 'Giemsa Stain Solution 500ml',
                'category' => ProcurementItem::CATEGORY_REAGENT,
                'unit_of_measure' => 'bottle',
                'specification' => 'Microscopy diagnostic Giemsa stain 500ml bottle',
                'reorder_level' => 8,
                'minimum_stock' => 3,
                'estimated_unit_cost' => 35000.00,
            ],
            [
                'code' => 'REA-002',
                'name' => 'Malaria Rapid Diagnostic Test (mRDT) Kits',
                'category' => ProcurementItem::CATEGORY_REAGENT,
                'unit_of_measure' => 'kit',
                'specification' => 'Pf/Pv antigen rapid detection kit, 25 tests/kit',
                'reorder_level' => 40,
                'minimum_stock' => 15,
                'estimated_unit_cost' => 45000.00,
            ],
            [
                'code' => 'RAD-001',
                'name' => 'Digital X-Ray Film 35x43cm',
                'category' => ProcurementItem::CATEGORY_RADIOLOGY,
                'unit_of_measure' => 'pack',
                'specification' => 'High resolution dry medical film, pack of 100',
                'reorder_level' => 10,
                'minimum_stock' => 4,
                'estimated_unit_cost' => 180000.00,
            ],
            [
                'code' => 'SUR-001',
                'name' => 'Suture Vicryl 2-0 with Curved Needle',
                'category' => ProcurementItem::CATEGORY_SURGICAL,
                'unit_of_measure' => 'box',
                'specification' => 'Synthetic absorbable surgical suture 2-0, box of 12',
                'reorder_level' => 15,
                'minimum_stock' => 5,
                'estimated_unit_cost' => 65000.00,
            ],
            [
                'code' => 'GEN-001',
                'name' => 'Hand Sanitizer Gel 5 Litres',
                'category' => ProcurementItem::CATEGORY_GENERAL,
                'unit_of_measure' => 'bottle',
                'specification' => '70% Isopropyl alcohol antiseptic gel, 5L canister',
                'reorder_level' => 12,
                'minimum_stock' => 5,
                'estimated_unit_cost' => 28000.00,
            ],
        ];

        foreach ($nonMeds as $itemData) {
            ProcurementItem::firstOrCreate(
                ['code' => $itemData['code']],
                array_merge($itemData, [
                    'requires_batch' => true,
                    'requires_expiry' => in_array($itemData['category'], [
                        ProcurementItem::CATEGORY_REAGENT,
                        ProcurementItem::CATEGORY_CONSUMABLE,
                        ProcurementItem::CATEGORY_SURGICAL,
                    ]),
                    'status' => 'active',
                ])
            );
        }

        // -------------------------------------------------------------
        // 3. Stores Setup
        // -------------------------------------------------------------
        $pharmacyDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Pharmacy%')->first();
        $labDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Laboratory%')->first();
        $radDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Radiology%')->first();
        $inpatientDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Inpatient%')->first()
            ?? Department::where('hospital_id', $hospital->id)->first();
        $opdDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Outpatient%')->first()
            ?? Department::where('hospital_id', $hospital->id)->first();

        $centralStore = Store::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'CST-01'],
            [
                'name' => 'Central Medical Store',
                'type' => Store::TYPE_CENTRAL,
                'is_default' => true,
                'description' => 'Main receiving and central bulk storage for hospital supplies',
                'status' => 'active',
            ]
        );

        $pharmacyStore = Store::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'PHM-01'],
            [
                'department_id' => $pharmacyDept?->id,
                'name' => 'Main Pharmacy Store',
                'type' => Store::TYPE_PHARMACY,
                'is_default' => false,
                'description' => 'Dispensary and outpatient pharmacy store',
                'status' => 'active',
            ]
        );

        $labStore = Store::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'LAB-01'],
            [
                'department_id' => $labDept?->id,
                'name' => 'Laboratory Sub-Store',
                'type' => Store::TYPE_LABORATORY,
                'is_default' => false,
                'description' => 'Diagnostic reagents and testing consumables holding store',
                'status' => 'active',
            ]
        );

        $theatreStore = Store::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'THE-01'],
            [
                'name' => 'Operating Theatre Sub-Store',
                'type' => Store::TYPE_THEATRE,
                'is_default' => false,
                'description' => 'Surgical kits, sutures, anaesthesia consumables',
                'status' => 'active',
            ]
        );

        $wardStore = Store::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'WRD-01'],
            [
                'department_id' => $inpatientDept?->id,
                'name' => 'Inpatient Ward Store',
                'type' => Store::TYPE_WARD,
                'is_default' => false,
                'description' => 'Ward inventory for inpatient nursing supplies',
                'status' => 'active',
            ]
        );

        $generalStore = Store::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'GEN-01'],
            [
                'name' => 'General Store',
                'type' => Store::TYPE_SUB_STORE,
                'is_default' => false,
                'description' => 'Cleaning, stationery, and non-clinical sundries store',
                'status' => 'active',
            ]
        );

        // -------------------------------------------------------------
        // 4. Vendors Directory
        // -------------------------------------------------------------
        $vendorMsd = Vendor::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'VND-MSD'],
            [
                'name' => 'Medical Stores Department (MSD)',
                'contact_person' => 'Juma Mwambene',
                'email' => 'orders@msd.go.tz',
                'phone' => '+255 22 2860140',
                'tax_id' => 'TIN-100-200-300 / VRN-40-001234-A',
                'address' => 'Keko Mwanga, Industrial Area, Dar es Salaam',
                'payment_terms' => 'Net 30 Days',
                'status' => 'active',
            ]
        );

        $vendorAnudha = Vendor::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'VND-ANUDHA'],
            [
                'name' => 'Anudha Limited',
                'contact_person' => 'Rajesh Patel',
                'email' => 'sales@anudha.com',
                'phone' => '+255 22 2125746',
                'tax_id' => 'TIN-200-300-400 / VRN-40-005678-B',
                'address' => 'Morogoro Road, City Centre, Dar es Salaam',
                'payment_terms' => 'Net 15 Days',
                'status' => 'active',
            ]
        );

        $vendorBahari = Vendor::firstOrCreate(
            ['hospital_id' => $hospital->id, 'code' => 'VND-BAHARI'],
            [
                'name' => 'Bahari Pharmacy Supplies Ltd',
                'contact_person' => 'Grace Mushi',
                'email' => 'info@baharipharmacy.co.tz',
                'phone' => '+255 754 112233',
                'tax_id' => 'TIN-300-400-500 / VRN-40-009988-C',
                'address' => 'Samora Avenue, Dar es Salaam',
                'payment_terms' => 'Net 30 Days',
                'status' => 'active',
            ]
        );

        // Fetch Procurement Items for seeding
        $itemGloves = ProcurementItem::where('code', 'CON-001')->first();
        $itemSyringes = ProcurementItem::where('code', 'CON-002')->first();
        $itemCannula = ProcurementItem::where('code', 'CON-003')->first();
        $itemRdt = ProcurementItem::where('code', 'REA-002')->first();
        $itemGiemsa = ProcurementItem::where('code', 'REA-001')->first();
        $itemVicryl = ProcurementItem::where('code', 'SUR-001')->first();
        $itemSanitizer = ProcurementItem::where('code', 'GEN-001')->first();
        $itemParacetamol = ProcurementItem::where('code', 'MED-00001')->first() ?? ProcurementItem::first();
        $itemAmox = ProcurementItem::where('code', 'MED-00006')->first() ?? ProcurementItem::skip(1)->first();

        // -------------------------------------------------------------
        // 5. Stock Items & Stock Batches (In Stock Inventory, Expiries & Low Stock)
        // -------------------------------------------------------------
        $seedInventory = [
            // Central Store healthy stock
            [
                'store' => $centralStore,
                'item' => $itemGloves,
                'batch' => 'BAT-GLV-2026A',
                'qty' => 150,
                'cost' => 11500.00,
                'loc' => 'Aisle 2 - Shelf B1',
                'mfg' => Carbon::now()->subMonths(3),
                'exp' => Carbon::now()->addMonths(24),
                'vendor' => $vendorMsd,
            ],
            [
                'store' => $centralStore,
                'item' => $itemSyringes,
                'batch' => 'BAT-SYR-2026A',
                'qty' => 200,
                'cost' => 14000.00,
                'loc' => 'Aisle 2 - Shelf B2',
                'mfg' => Carbon::now()->subMonths(2),
                'exp' => Carbon::now()->addMonths(30),
                'vendor' => $vendorAnudha,
            ],
            [
                'store' => $centralStore,
                'item' => $itemVicryl,
                'batch' => 'BAT-VIC-2026A',
                'qty' => 45,
                'cost' => 62000.00,
                'loc' => 'Surgical Cold Cabinet 1',
                'mfg' => Carbon::now()->subMonths(4),
                'exp' => Carbon::now()->addMonths(18),
                'vendor' => $vendorMsd,
            ],
            // EXPIRING SOON BATCH (within 35 days -> triggers expiring batches alert <= 60 days)
            [
                'store' => $centralStore,
                'item' => $itemRdt,
                'batch' => 'BAT-RDT-EXP35',
                'qty' => 30,
                'cost' => 42000.00,
                'loc' => 'Diagnostics Refrigerator 2',
                'mfg' => Carbon::now()->subMonths(11),
                'exp' => Carbon::now()->addDays(35),
                'vendor' => $vendorBahari,
            ],
            // Expiring soon batch in Lab Store (within 45 days)
            [
                'store' => $labStore,
                'item' => $itemGiemsa,
                'batch' => 'BAT-GIE-EXP45',
                'qty' => 8,
                'cost' => 34000.00,
                'loc' => 'Bench Staining Shelf',
                'mfg' => Carbon::now()->subMonths(10),
                'exp' => Carbon::now()->addDays(45),
                'vendor' => $vendorAnudha,
            ],
            // LOW STOCK in Pharmacy Store (Qty 12 < Reorder 100 -> triggers reorder alert)
            [
                'store' => $pharmacyStore,
                'item' => $itemParacetamol,
                'batch' => 'BAT-PCM-PHARM',
                'qty' => 12,
                'cost' => 5.00,
                'loc' => 'Dispensary Shelf 1A',
                'mfg' => Carbon::now()->subMonths(6),
                'exp' => Carbon::now()->addMonths(14),
                'vendor' => $vendorMsd,
            ],
            // Healthy stock in Pharmacy Store
            [
                'store' => $pharmacyStore,
                'item' => $itemAmox,
                'batch' => 'BAT-AMX-PHARM',
                'qty' => 350,
                'cost' => 18.00,
                'loc' => 'Antibiotics Section Shelf 3',
                'mfg' => Carbon::now()->subMonths(2),
                'exp' => Carbon::now()->addMonths(20),
                'vendor' => $vendorBahari,
            ],
            // Sanitizer in Central Store
            [
                'store' => $centralStore,
                'item' => $itemSanitizer,
                'batch' => 'BAT-SAN-2026A',
                'qty' => 60,
                'cost' => 26000.00,
                'loc' => 'General Sundries Pallet 4',
                'mfg' => Carbon::now()->subMonths(1),
                'exp' => Carbon::now()->addMonths(36),
                'vendor' => $vendorAnudha,
            ],
        ];

        $createdBatches = [];

        foreach ($seedInventory as $inv) {
            if (! $inv['item']) {
                continue;
            }

            $stockItem = null;
            if ($inv['item']->medication_id) {
                $stockItem = StockItem::where('hospital_id', $hospital->id)
                    ->where('medication_id', $inv['item']->medication_id)
                    ->first();
                if ($stockItem) {
                    $stockItem->update([
                        'store_id' => $inv['store']->id,
                        'procurement_item_id' => $inv['item']->id,
                        'reorder_level' => $inv['item']->reorder_level,
                    ]);
                }
            }

            if (! $stockItem) {
                $stockItem = StockItem::where('hospital_id', $hospital->id)
                    ->where('store_id', $inv['store']->id)
                    ->where('procurement_item_id', $inv['item']->id)
                    ->first();
            }

            if (! $stockItem) {
                $stockItem = StockItem::create([
                    'hospital_id' => $hospital->id,
                    'store_id' => $inv['store']->id,
                    'medication_id' => $inv['item']->medication_id,
                    'procurement_item_id' => $inv['item']->id,
                    'reorder_level' => $inv['item']->reorder_level,
                    'maximum_level' => $inv['item']->reorder_level * 4,
                    'status' => 'active',
                    'created_by' => $storeOfficer->id,
                ]);
            }

            $batch = StockBatch::updateOrCreate(
                [
                    'stock_item_id' => $stockItem->id,
                    'batch_number' => $inv['batch'],
                ],
                [
                    'store_id' => $inv['store']->id,
                    'vendor_id' => $inv['vendor']->id,
                    'storage_location' => $inv['loc'],
                    'manufacture_date' => $inv['mfg'],
                    'expiry_date' => $inv['exp'],
                    'quantity' => $inv['qty'],
                    'unit_cost' => $inv['cost'],
                    'supplier_name' => $inv['vendor']->name,
                    'status' => StockBatch::STATUS_ACTIVE,
                    'created_by' => $storeOfficer->id,
                ]
            );

            $createdBatches[$inv['batch']] = $batch;

            // Audit ledger transaction
            StockTransaction::firstOrCreate(
                [
                    'stock_batch_id' => $batch->id,
                    'transaction_type' => StockTransaction::TYPE_RECEIPT,
                ],
                [
                    'stock_item_id' => $stockItem->id,
                    'quantity' => $inv['qty'],
                    'balance_before' => 0,
                    'balance_after' => $inv['qty'],
                    'reason' => 'Initial stock intake & verification',
                    'performed_by' => $storeOfficer->id,
                    'occurred_at' => Carbon::now()->subDays(15),
                ]
            );
        }

        // -------------------------------------------------------------
        // 6. Indents / Purchase Requisitions
        // -------------------------------------------------------------
        // Indent 1: SUBMITTED (Pending review -> lights up pending_indents on dashboard)
        $indentSubmitted = Indent::updateOrCreate(
            ['indent_number' => 'IND-2026-0002'],
            [
                'hospital_id' => $hospital->id,
                'department_id' => $labDept?->id ?? $hospital->departments()->first()->id,
                'store_id' => $centralStore->id,
                'requested_by' => $labTech->id,
                'required_date' => Carbon::now()->addDays(10),
                'priority' => Indent::PRIORITY_URGENT,
                'status' => Indent::STATUS_SUBMITTED,
                'justification' => 'Rapid malaria testing spike due to seasonal rains; reagent stock below safety threshold.',
                'created_by' => $labTech->id,
            ]
        );

        IndentItem::updateOrCreate(
            ['indent_id' => $indentSubmitted->id, 'procurement_item_id' => $itemRdt->id],
            [
                'item_name' => $itemRdt->name,
                'unit_of_measure' => $itemRdt->unit_of_measure,
                'quantity_requested' => 50,
                'quantity_approved' => 50,
                'quantity_ordered' => 0,
                'specifications' => 'Must have minimum 18 months shelf life on delivery',
                'justification' => 'Current stock only sufficient for 4 days',
                'status' => 'pending',
            ]
        );

        IndentItem::updateOrCreate(
            ['indent_id' => $indentSubmitted->id, 'procurement_item_id' => $itemGiemsa->id],
            [
                'item_name' => $itemGiemsa->name,
                'unit_of_measure' => $itemGiemsa->unit_of_measure,
                'quantity_requested' => 10,
                'quantity_approved' => 10,
                'quantity_ordered' => 0,
                'specifications' => 'Certified diagnostic grade',
                'justification' => 'Microscopy confirmation for suspected complicated malaria',
                'status' => 'pending',
            ]
        );

        // Indent 2: APPROVED (Pharmacy Department Requisition)
        $indentApproved = Indent::updateOrCreate(
            ['indent_number' => 'IND-2026-0001'],
            [
                'hospital_id' => $hospital->id,
                'department_id' => $pharmacyDept?->id ?? $hospital->departments()->first()->id,
                'store_id' => $centralStore->id,
                'requested_by' => $pharmacist->id,
                'required_date' => Carbon::now()->addDays(5),
                'priority' => Indent::PRIORITY_NORMAL,
                'status' => Indent::STATUS_APPROVED,
                'justification' => 'Monthly replenishment of primary antibiotic syrups and basic analgesics approved as per FY2026 Q4 drug schedule.',
                'approved_by' => $purchaseManager->id,
                'approved_at' => Carbon::now()->subDays(10),
                'created_by' => $pharmacist->id,
            ]
        );

        IndentItem::updateOrCreate(
            ['indent_id' => $indentApproved->id, 'procurement_item_id' => $itemParacetamol->id],
            [
                'item_name' => $itemParacetamol->name,
                'unit_of_measure' => $itemParacetamol->unit_of_measure,
                'quantity_requested' => 5000,
                'quantity_approved' => 5000,
                'quantity_ordered' => 5000,
                'specifications' => 'Blister packs 10x10',
                'status' => 'approved',
            ]
        );

        IndentItem::updateOrCreate(
            ['indent_id' => $indentApproved->id, 'procurement_item_id' => $itemCannula->id],
            [
                'item_name' => $itemCannula->name,
                'unit_of_measure' => $itemCannula->unit_of_measure,
                'quantity_requested' => 30,
                'quantity_approved' => 30,
                'quantity_ordered' => 30,
                'specifications' => 'Pink 20G with needle lock',
                'status' => 'approved',
            ]
        );

        // Indent 3: FULFILLED (Operating Theatre Requisition)
        $indentFulfilled = Indent::updateOrCreate(
            ['indent_number' => 'IND-2026-0003'],
            [
                'hospital_id' => $hospital->id,
                'department_id' => $inpatientDept?->id ?? $hospital->departments()->first()->id,
                'store_id' => $centralStore->id,
                'requested_by' => $deptUser->id,
                'required_date' => Carbon::now()->subDays(10),
                'priority' => Indent::PRIORITY_NORMAL,
                'status' => Indent::STATUS_FULFILLED,
                'justification' => 'Quarterly surgical suture bulk replenishment for Operating Theatres 1, 2 and Minor Theatre.',
                'approved_by' => $purchaseManager->id,
                'approved_at' => Carbon::now()->subDays(24),
                'created_by' => $deptUser->id,
            ]
        );

        IndentItem::updateOrCreate(
            ['indent_id' => $indentFulfilled->id, 'procurement_item_id' => $itemVicryl->id],
            [
                'item_name' => $itemVicryl->name,
                'unit_of_measure' => $itemVicryl->unit_of_measure,
                'quantity_requested' => 20,
                'quantity_approved' => 20,
                'quantity_ordered' => 20,
                'status' => 'ordered',
            ]
        );

        IndentItem::updateOrCreate(
            ['indent_id' => $indentFulfilled->id, 'procurement_item_id' => $itemGloves->id],
            [
                'item_name' => $itemGloves->name,
                'unit_of_measure' => $itemGloves->unit_of_measure,
                'quantity_requested' => 50,
                'quantity_approved' => 50,
                'quantity_ordered' => 50,
                'status' => 'ordered',
            ]
        );

        // Indent 4: DRAFT (Ward supplies)
        Indent::updateOrCreate(
            ['indent_number' => 'IND-2026-0004'],
            [
                'hospital_id' => $hospital->id,
                'department_id' => $inpatientDept?->id ?? $hospital->departments()->first()->id,
                'store_id' => $centralStore->id,
                'requested_by' => $nurse->id,
                'required_date' => Carbon::now()->addDays(7),
                'priority' => Indent::PRIORITY_NORMAL,
                'status' => Indent::STATUS_DRAFT,
                'justification' => 'Routine ward stock top up.',
                'created_by' => $nurse->id,
            ]
        );

        // -------------------------------------------------------------
        // 7. Vendor Quotations & RFQ Evaluation (For Quotation Comparison Matrix)
        // -------------------------------------------------------------
        // Quote 1: MSD (Selected)
        $quoteMsd = VendorQuotation::updateOrCreate(
            ['quotation_number' => 'RFQ-2026-MSD-01'],
            [
                'hospital_id' => $hospital->id,
                'indent_id' => $indentFulfilled->id,
                'vendor_id' => $vendorMsd->id,
                'reference_no' => 'MSD/QUOTE/2026/8819',
                'quotation_date' => Carbon::now()->subDays(22),
                'valid_until' => Carbon::now()->addDays(30),
                'lead_time_days' => 5,
                'subtotal' => 1840000.00,
                'discount_amount' => 50000.00,
                'tax_amount' => 0.00,
                'shipping_cost' => 0.00,
                'total_amount' => 1790000.00,
                'payment_terms' => 'Net 30 Days',
                'delivery_terms' => 'Free on Board - Hospital Delivery',
                'status' => VendorQuotation::STATUS_SELECTED,
                'selection_justification' => 'Lowest compliant bid meeting all surgical grade specifications with guaranteed stock delivery.',
                'selected_by' => $purchaseManager->id,
                'selected_at' => Carbon::now()->subDays(20),
                'created_by' => $purchaseOfficer->id,
            ]
        );

        VendorQuotationItem::updateOrCreate(
            ['vendor_quotation_id' => $quoteMsd->id, 'procurement_item_id' => $itemVicryl->id],
            [
                'item_name' => $itemVicryl->name,
                'quantity' => 20,
                'unit_price' => 62000.00,
                'total_price' => 1240000.00,
                'remarks' => 'Original Ethicon manufactured',
            ]
        );

        VendorQuotationItem::updateOrCreate(
            ['vendor_quotation_id' => $quoteMsd->id, 'procurement_item_id' => $itemGloves->id],
            [
                'item_name' => $itemGloves->name,
                'quantity' => 50,
                'unit_price' => 12000.00,
                'total_price' => 600000.00,
                'remarks' => 'Box of 100 pcs latex exam',
            ]
        );

        // Quote 2: Anudha Limited (Submitted / Evaluated)
        $quoteAnudha = VendorQuotation::updateOrCreate(
            ['quotation_number' => 'RFQ-2026-AND-02'],
            [
                'hospital_id' => $hospital->id,
                'indent_id' => $indentFulfilled->id,
                'vendor_id' => $vendorAnudha->id,
                'reference_no' => 'AND-QT-9921',
                'quotation_date' => Carbon::now()->subDays(22),
                'valid_until' => Carbon::now()->addDays(20),
                'lead_time_days' => 7,
                'subtotal' => 1970000.00,
                'discount_amount' => 0.00,
                'tax_amount' => 0.00,
                'shipping_cost' => 15000.00,
                'total_amount' => 1985000.00,
                'payment_terms' => 'Net 15 Days',
                'delivery_terms' => 'Hospital Gate Delivery',
                'status' => VendorQuotation::STATUS_SUBMITTED,
                'created_by' => $purchaseOfficer->id,
            ]
        );

        VendorQuotationItem::updateOrCreate(
            ['vendor_quotation_id' => $quoteAnudha->id, 'procurement_item_id' => $itemVicryl->id],
            [
                'item_name' => $itemVicryl->name,
                'quantity' => 20,
                'unit_price' => 66000.00,
                'total_price' => 1320000.00,
                'remarks' => 'B. Braun equivalent suture',
            ]
        );

        VendorQuotationItem::updateOrCreate(
            ['vendor_quotation_id' => $quoteAnudha->id, 'procurement_item_id' => $itemGloves->id],
            [
                'item_name' => $itemGloves->name,
                'quantity' => 50,
                'unit_price' => 13000.00,
                'total_price' => 650000.00,
                'remarks' => 'Powder free nitriles',
            ]
        );

        // Quote 3: Bahari Supplies (Rejected - higher price)
        VendorQuotation::updateOrCreate(
            ['quotation_number' => 'RFQ-2026-BHR-03'],
            [
                'hospital_id' => $hospital->id,
                'indent_id' => $indentFulfilled->id,
                'vendor_id' => $vendorBahari->id,
                'reference_no' => 'BHR-SALES-441',
                'quotation_date' => Carbon::now()->subDays(22),
                'valid_until' => Carbon::now()->addDays(15),
                'lead_time_days' => 12,
                'subtotal' => 2150000.00,
                'discount_amount' => 0.00,
                'tax_amount' => 0.00,
                'shipping_cost' => 25000.00,
                'total_amount' => 2175000.00,
                'payment_terms' => 'Advance Payment',
                'delivery_terms' => 'Ex-Warehouse',
                'status' => VendorQuotation::STATUS_REJECTED,
                'selection_justification' => 'Rejected: 21% higher than benchmark tender price and unfavorable advance payment terms.',
                'created_by' => $purchaseOfficer->id,
            ]
        );

        // -------------------------------------------------------------
        // 8. Purchase Orders Across Complete Lifecycle
        // -------------------------------------------------------------
        // PO 1: FULLY_RECEIVED (PO-2026-001 to MSD, linked to RFQ & Indent 3)
        $poFullyReceived = PurchaseOrder::updateOrCreate(
            ['po_number' => 'PO-2026-001'],
            [
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorMsd->id,
                'receiving_store_id' => $centralStore->id,
                'indent_id' => $indentFulfilled->id,
                'vendor_quotation_id' => $quoteMsd->id,
                'order_date' => Carbon::now()->subDays(18),
                'expected_delivery_date' => Carbon::now()->subDays(12),
                'subtotal' => 1840000.00,
                'tax_amount' => 0.00,
                'discount_amount' => 50000.00,
                'shipping_amount' => 0.00,
                'grand_total' => 1790000.00,
                'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
                'terms_conditions' => 'Standard MSD public health supply delivery agreement. Net 30 days.',
                'notes' => 'Bulk surgical order fully fulfilled and inspected.',
                'created_by' => $purchaseOfficer->id,
                'approved_by' => $purchaseManager->id,
                'approved_at' => Carbon::now()->subDays(17),
            ]
        );

        $po1ItemVicryl = PurchaseOrderItem::updateOrCreate(
            ['purchase_order_id' => $poFullyReceived->id, 'procurement_item_id' => $itemVicryl->id],
            [
                'item_name' => $itemVicryl->name,
                'unit_of_measure' => $itemVicryl->unit_of_measure,
                'quantity_ordered' => 20,
                'quantity_received' => 20,
                'unit_price' => 62000.00,
                'total_amount' => 1240000.00,
                'specifications' => 'Suture Vicryl 2-0 with Curved Needle 36mm',
            ]
        );

        $po1ItemGloves = PurchaseOrderItem::updateOrCreate(
            ['purchase_order_id' => $poFullyReceived->id, 'procurement_item_id' => $itemGloves->id],
            [
                'item_name' => $itemGloves->name,
                'unit_of_measure' => $itemGloves->unit_of_measure,
                'quantity_ordered' => 50,
                'quantity_received' => 50,
                'unit_price' => 12000.00,
                'discount_amount' => 50000.00,
                'total_amount' => 550000.00,
                'specifications' => 'Latex Medium Powder Free',
            ]
        );

        // PO 2: PARTIALLY_RECEIVED (PO-2026-002 to Bahari Pharmacy Supplies -> active PO on dashboard)
        $poPartiallyReceived = PurchaseOrder::updateOrCreate(
            ['po_number' => 'PO-2026-002'],
            [
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorBahari->id,
                'receiving_store_id' => $centralStore->id,
                'order_date' => Carbon::now()->subDays(8),
                'expected_delivery_date' => Carbon::now()->subDays(1),
                'subtotal' => 2750000.00,
                'tax_amount' => 0.00,
                'discount_amount' => 0.00,
                'shipping_amount' => 0.00,
                'grand_total' => 2750000.00,
                'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                'terms_conditions' => 'Payment upon partial GRN acceptance and matching invoice approval.',
                'notes' => 'First consignment delivered; second batch pending vendor customs clearance.',
                'created_by' => $purchaseOfficer->id,
                'approved_by' => $purchaseManager->id,
                'approved_at' => Carbon::now()->subDays(7),
            ]
        );

        $po2ItemRdt = PurchaseOrderItem::updateOrCreate(
            ['purchase_order_id' => $poPartiallyReceived->id, 'procurement_item_id' => $itemRdt->id],
            [
                'item_name' => $itemRdt->name,
                'unit_of_measure' => $itemRdt->unit_of_measure,
                'quantity_ordered' => 40,
                'quantity_received' => 20, // 20 received so far
                'unit_price' => 45000.00,
                'total_amount' => 1800000.00,
                'specifications' => 'Malaria Pf/Pv antigen tests 25/kit',
            ]
        );

        $po2ItemSyringes = PurchaseOrderItem::updateOrCreate(
            ['purchase_order_id' => $poPartiallyReceived->id, 'procurement_item_id' => $itemSyringes->id],
            [
                'item_name' => $itemSyringes->name,
                'unit_of_measure' => $itemSyringes->unit_of_measure,
                'quantity_ordered' => 60,
                'quantity_received' => 30,
                'unit_price' => 15000.00,
                'total_amount' => 900000.00,
                'discount_amount' => 50000.00,
                'specifications' => 'Sterile 5ml with 21G needles',
            ]
        );

        // PO 3: SENT_TO_VENDOR (PO-2026-003 to Anudha Limited -> active PO on dashboard)
        PurchaseOrder::updateOrCreate(
            ['po_number' => 'PO-2026-003'],
            [
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorAnudha->id,
                'receiving_store_id' => $centralStore->id,
                'order_date' => Carbon::now()->subDays(3),
                'expected_delivery_date' => Carbon::now()->addDays(5),
                'subtotal' => 3200000.00,
                'tax_amount' => 0.00,
                'discount_amount' => 0.00,
                'shipping_amount' => 0.00,
                'grand_total' => 3200000.00,
                'status' => PurchaseOrder::STATUS_SENT_TO_VENDOR,
                'terms_conditions' => 'Official hospital purchase order dispatched via vendor portal.',
                'notes' => 'Vendor acknowledged receipt and confirmed dispatch schedule.',
                'created_by' => $purchaseOfficer->id,
                'approved_by' => $purchaseManager->id,
                'approved_at' => Carbon::now()->subDays(2),
            ]
        );

        // PO 4: APPROVED (PO-2026-004 -> active PO awaiting vendor dispatch)
        PurchaseOrder::updateOrCreate(
            ['po_number' => 'PO-2026-004'],
            [
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorMsd->id,
                'receiving_store_id' => $centralStore->id,
                'order_date' => Carbon::now()->subDay(),
                'expected_delivery_date' => Carbon::now()->addDays(10),
                'subtotal' => 1500000.00,
                'tax_amount' => 0.00,
                'discount_amount' => 0.00,
                'shipping_amount' => 0.00,
                'grand_total' => 1500000.00,
                'status' => PurchaseOrder::STATUS_APPROVED,
                'terms_conditions' => 'Standard terms apply.',
                'notes' => 'Awaiting procurement officer formal transmission.',
                'created_by' => $purchaseOfficer->id,
                'approved_by' => $purchaseManager->id,
                'approved_at' => Carbon::now()->subDay(),
            ]
        );

        // -------------------------------------------------------------
        // 9. Goods Receipt Notes (GRN)
        // -------------------------------------------------------------
        // GRN 1: POSTED (Against PO-2026-001 from MSD, fully verified and posted into stock)
        $grnPosted = GoodsReceiptNote::updateOrCreate(
            ['grn_number' => 'GRN-2026-0001'],
            [
                'purchase_order_id' => $poFullyReceived->id,
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorMsd->id,
                'store_id' => $centralStore->id,
                'received_by' => $storeOfficer->id,
                'received_date' => Carbon::now()->subDays(14),
                'delivery_note_number' => 'DN-MSD-77189',
                'invoice_reference' => 'INV-MSD-9021',
                'status' => GoodsReceiptNote::STATUS_POSTED,
                'inspection_notes' => 'Physical inspection completed. Package seals intact, batch numbers match manufacturer certificate of analysis.',
                'inspected_by' => $pharmacist->id,
                'inspection_date' => Carbon::now()->subDays(13),
                'posted_by' => $storeManager->id,
                'posted_at' => Carbon::now()->subDays(13),
                'remarks' => 'Clean delivery, all items taken on charge into Central Medical Store.',
                'created_by' => $storeOfficer->id,
            ]
        );

        $grn1ItemVicryl = GoodsReceiptNoteItem::updateOrCreate(
            ['goods_receipt_note_id' => $grnPosted->id, 'purchase_order_item_id' => $po1ItemVicryl->id],
            [
                'procurement_item_id' => $itemVicryl->id,
                'item_name' => $itemVicryl->name,
                'unit_of_measure' => $itemVicryl->unit_of_measure,
                'unit_cost' => 62000.00,
                'quantity_ordered' => 20,
                'quantity_delivered' => 20,
                'quantity_accepted' => 20,
                'quantity_rejected' => 0,
                'quantity_short' => 0,
                'batch_number' => 'BAT-VIC-2026A',
                'manufacture_date' => Carbon::now()->subMonths(4),
                'expiry_date' => Carbon::now()->addMonths(18),
                'storage_location' => 'Surgical Cold Cabinet 1',
                'stock_batch_id' => $createdBatches['BAT-VIC-2026A']->id ?? null,
            ]
        );

        $grn1ItemGloves = GoodsReceiptNoteItem::updateOrCreate(
            ['goods_receipt_note_id' => $grnPosted->id, 'purchase_order_item_id' => $po1ItemGloves->id],
            [
                'procurement_item_id' => $itemGloves->id,
                'item_name' => $itemGloves->name,
                'unit_of_measure' => $itemGloves->unit_of_measure,
                'unit_cost' => 11500.00,
                'quantity_ordered' => 50,
                'quantity_delivered' => 50,
                'quantity_accepted' => 50,
                'quantity_rejected' => 0,
                'quantity_short' => 0,
                'batch_number' => 'BAT-GLV-2026A',
                'manufacture_date' => Carbon::now()->subMonths(3),
                'expiry_date' => Carbon::now()->addMonths(24),
                'storage_location' => 'Aisle 2 - Shelf B1',
                'stock_batch_id' => $createdBatches['BAT-GLV-2026A']->id ?? null,
            ]
        );

        // GRN 2: INSPECTION_PENDING (Against PO-2026-002 from Bahari -> lights up pending_grns on both dashboards!)
        $grnPending = GoodsReceiptNote::updateOrCreate(
            ['grn_number' => 'GRN-2026-0002'],
            [
                'purchase_order_id' => $poPartiallyReceived->id,
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorBahari->id,
                'store_id' => $centralStore->id,
                'received_by' => $storeOfficer->id,
                'received_date' => Carbon::now()->subDay(),
                'delivery_note_number' => 'DN-BHR-4412',
                'invoice_reference' => 'INV-BHR-4412',
                'status' => GoodsReceiptNote::STATUS_INSPECTION_PENDING,
                'inspection_notes' => 'Goods unloaded at Central Store Receiving Dock 1. Cold chain temperature data logger verified at 4.2°C. Awaiting technical QC inspection.',
                'remarks' => 'Consignment 1 of 2. Awaiting QA testing before posting to ledger.',
                'created_by' => $storeOfficer->id,
            ]
        );

        GoodsReceiptNoteItem::updateOrCreate(
            ['goods_receipt_note_id' => $grnPending->id, 'purchase_order_item_id' => $po2ItemRdt->id],
            [
                'procurement_item_id' => $itemRdt->id,
                'item_name' => $itemRdt->name,
                'unit_of_measure' => $itemRdt->unit_of_measure,
                'unit_cost' => 45000.00,
                'quantity_ordered' => 40,
                'quantity_delivered' => 20,
                'quantity_accepted' => 0,
                'quantity_rejected' => 0,
                'quantity_short' => 20,
                'batch_number' => 'BAT-RDT-2026B',
                'manufacture_date' => Carbon::now()->subMonths(2),
                'expiry_date' => Carbon::now()->addMonths(20),
                'storage_location' => 'Receiving Quarantine Dock',
            ]
        );

        GoodsReceiptNoteItem::updateOrCreate(
            ['goods_receipt_note_id' => $grnPending->id, 'purchase_order_item_id' => $po2ItemSyringes->id],
            [
                'procurement_item_id' => $itemSyringes->id,
                'item_name' => $itemSyringes->name,
                'unit_of_measure' => $itemSyringes->unit_of_measure,
                'unit_cost' => 15000.00,
                'quantity_ordered' => 60,
                'quantity_delivered' => 30,
                'quantity_accepted' => 0,
                'quantity_rejected' => 0,
                'quantity_short' => 30,
                'batch_number' => 'BAT-SYR-2026B',
                'manufacture_date' => Carbon::now()->subMonths(1),
                'expiry_date' => Carbon::now()->addMonths(36),
                'storage_location' => 'Receiving Quarantine Dock',
            ]
        );

        // -------------------------------------------------------------
        // 10. Vendor Invoices & 3-Way Matching
        // -------------------------------------------------------------
        // Invoice 1: MATCHED & PAID (Clean 3-way match: PO <-> GRN <-> Vendor Invoice)
        $invClean = VendorInvoice::updateOrCreate(
            ['system_invoice_number' => 'VINV-2026-0001'],
            [
                'invoice_number' => 'INV-MSD-9021',
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorMsd->id,
                'purchase_order_id' => $poFullyReceived->id,
                'goods_receipt_note_id' => $grnPosted->id,
                'invoice_date' => Carbon::now()->subDays(12),
                'due_date' => Carbon::now()->addDays(18),
                'subtotal' => 1840000.00,
                'tax_amount' => 0.00,
                'discount_amount' => 50000.00,
                'grand_total' => 1790000.00,
                'paid_amount' => 1790000.00,
                'match_status' => VendorInvoice::MATCH_STATUS_MATCHED,
                'status' => VendorInvoice::STATUS_PAID,
                'verified_by' => $accountant->id,
                'verified_at' => Carbon::now()->subDays(11),
                'approved_by' => $purchaseManager->id,
                'approved_at' => Carbon::now()->subDays(10),
                'created_by' => $accountant->id,
            ]
        );

        VendorInvoiceItem::updateOrCreate(
            ['vendor_invoice_id' => $invClean->id, 'purchase_order_item_id' => $po1ItemVicryl->id],
            [
                'goods_receipt_note_item_id' => $grn1ItemVicryl->id,
                'procurement_item_id' => $itemVicryl->id,
                'item_name' => $itemVicryl->name,
                'quantity' => 20,
                'unit_price' => 62000.00,
                'total_price' => 1240000.00,
                'matched_po_qty' => 20,
                'matched_grn_qty' => 20,
                'matched_po_price' => 62000.00,
                'has_discrepancy' => false,
            ]
        );

        VendorInvoiceItem::updateOrCreate(
            ['vendor_invoice_id' => $invClean->id, 'purchase_order_item_id' => $po1ItemGloves->id],
            [
                'goods_receipt_note_item_id' => $grn1ItemGloves->id,
                'procurement_item_id' => $itemGloves->id,
                'item_name' => $itemGloves->name,
                'quantity' => 50,
                'unit_price' => 12000.00,
                'total_price' => 600000.00,
                'matched_po_qty' => 50,
                'matched_grn_qty' => 50,
                'matched_po_price' => 12000.00,
                'has_discrepancy' => false,
            ]
        );

        // Invoice 2: DISCREPANCY (Lights up invoice_discrepancies on procurement dashboard & reports!)
        $invDiscrepancy = VendorInvoice::updateOrCreate(
            ['system_invoice_number' => 'VINV-2026-0002'],
            [
                'invoice_number' => 'INV-BHR-4412',
                'hospital_id' => $hospital->id,
                'vendor_id' => $vendorBahari->id,
                'purchase_order_id' => $poPartiallyReceived->id,
                'goods_receipt_note_id' => $grnPending->id,
                'invoice_date' => Carbon::now()->subDays(2),
                'due_date' => Carbon::now()->addDays(28),
                'subtotal' => 1550000.00,
                'tax_amount' => 0.00,
                'discount_amount' => 0.00,
                'grand_total' => 1550000.00,
                'paid_amount' => 0.00,
                'match_status' => VendorInvoice::MATCH_STATUS_DISCREPANCY,
                'status' => VendorInvoice::STATUS_DISCREPANCY,
                'discrepancy_notes' => 'Price variance detected: Vendor invoiced Syringes 5ml at TZS 17,500/box instead of agreed PO contract rate of TZS 15,000/box. Overbilled by TZS 75,000.',
                'verified_by' => $accountant->id,
                'verified_at' => Carbon::now()->subDay(),
                'created_by' => $accountant->id,
            ]
        );

        VendorInvoiceItem::updateOrCreate(
            ['vendor_invoice_id' => $invDiscrepancy->id, 'purchase_order_item_id' => $po2ItemSyringes->id],
            [
                'procurement_item_id' => $itemSyringes->id,
                'item_name' => $itemSyringes->name,
                'quantity' => 30,
                'unit_price' => 17500.00, // Invoiced higher
                'total_price' => 525000.00,
                'matched_po_qty' => 30,
                'matched_grn_qty' => 30,
                'matched_po_price' => 15000.00, // Contract price
                'has_discrepancy' => true,
                'discrepancy_reason' => 'Unit cost overcharge: Invoiced at TZS 17,500 vs Approved PO rate TZS 15,000.',
            ]
        );

        // -------------------------------------------------------------
        // 11. Department Stock Requests (Department -> Store)
        // -------------------------------------------------------------
        // Request 1: SUBMITTED (Inpatient Ward to Central Store -> lights up pending_requests on stores dashboard)
        $reqSubmitted = DepartmentStockRequest::updateOrCreate(
            ['request_number' => 'DSR-2026-0002'],
            [
                'hospital_id' => $hospital->id,
                'department_id' => $inpatientDept?->id ?? $hospital->departments()->first()->id,
                'from_store_id' => $centralStore->id,
                'requested_by' => $nurse->id,
                'priority' => DepartmentStockRequest::PRIORITY_URGENT,
                'status' => DepartmentStockRequest::STATUS_SUBMITTED,
                'notes' => 'Urgent replenishment for Inpatient Surgical Ward 3A after weekend admissions.',
                'created_by' => $nurse->id,
            ]
        );

        DepartmentStockRequestItem::updateOrCreate(
            ['department_stock_request_id' => $reqSubmitted->id, 'procurement_item_id' => $itemGloves->id],
            [
                'item_name' => $itemGloves->name,
                'unit_of_measure' => $itemGloves->unit_of_measure,
                'quantity_requested' => 10,
                'quantity_approved' => 0,
                'quantity_issued' => 0,
                'remarks' => 'Gloves running critically low in Ward dressing station',
            ]
        );

        DepartmentStockRequestItem::updateOrCreate(
            ['department_stock_request_id' => $reqSubmitted->id, 'procurement_item_id' => $itemCannula->id],
            [
                'item_name' => $itemCannula->name,
                'unit_of_measure' => $itemCannula->unit_of_measure,
                'quantity_requested' => 5,
                'quantity_approved' => 0,
                'quantity_issued' => 0,
                'remarks' => 'IV Cannulas 20G',
            ]
        );

        // Request 2: ISSUED (Outpatient Pharmacy to Central Store -> Fulfilled)
        $reqIssued = DepartmentStockRequest::updateOrCreate(
            ['request_number' => 'DSR-2026-0001'],
            [
                'hospital_id' => $hospital->id,
                'department_id' => $pharmacyDept?->id ?? $hospital->departments()->first()->id,
                'from_store_id' => $centralStore->id,
                'requested_by' => $pharmacist->id,
                'priority' => DepartmentStockRequest::PRIORITY_NORMAL,
                'status' => DepartmentStockRequest::STATUS_ISSUED,
                'notes' => 'Weekly dispensary consumable transfer.',
                'approved_by' => $storeManager->id,
                'approved_at' => Carbon::now()->subDays(3),
                'created_by' => $pharmacist->id,
            ]
        );

        DepartmentStockRequestItem::updateOrCreate(
            ['department_stock_request_id' => $reqIssued->id, 'procurement_item_id' => $itemSanitizer->id],
            [
                'item_name' => $itemSanitizer->name,
                'unit_of_measure' => $itemSanitizer->unit_of_measure,
                'quantity_requested' => 5,
                'quantity_approved' => 5,
                'quantity_issued' => 5,
                'remarks' => 'Hand sanitizer for dispensing counter stations',
            ]
        );

        // -------------------------------------------------------------
        // 12. Store Issues (Store Outward / Department Distribution)
        // -------------------------------------------------------------
        // Issue 1: ISSUED / FULFILLED against Request 2
        $issue1 = StoreIssue::updateOrCreate(
            ['issue_number' => 'ISS-2026-0001'],
            [
                'hospital_id' => $hospital->id,
                'from_store_id' => $centralStore->id,
                'department_id' => $pharmacyDept?->id ?? $hospital->departments()->first()->id,
                'department_stock_request_id' => $reqIssued->id,
                'issued_by' => $storeOfficer->id,
                'issued_at' => Carbon::now()->subDays(2),
                'received_by' => $pharmacist->id,
                'received_at' => Carbon::now()->subDays(2),
                'status' => StoreIssue::STATUS_RECEIVED,
                'remarks' => 'Issued to Main Pharmacy Dispensary counter.',
                'created_by' => $storeOfficer->id,
            ]
        );

        StoreIssueItem::updateOrCreate(
            ['store_issue_id' => $issue1->id, 'procurement_item_id' => $itemSanitizer->id],
            [
                'stock_batch_id' => $createdBatches['BAT-SAN-2026A']->id ?? 1,
                'item_name' => $itemSanitizer->name,
                'batch_number' => 'BAT-SAN-2026A',
                'expiry_date' => Carbon::now()->addMonths(36),
                'quantity_issued' => 5,
                'unit_cost' => 26000.00,
                'remarks' => '5 canisters issued',
            ]
        );

        // Issue 2: Direct issue to Inpatient Ward
        $issue2 = StoreIssue::updateOrCreate(
            ['issue_number' => 'ISS-2026-0002'],
            [
                'hospital_id' => $hospital->id,
                'from_store_id' => $centralStore->id,
                'department_id' => $inpatientDept?->id ?? $hospital->departments()->first()->id,
                'issued_by' => $storeOfficer->id,
                'issued_at' => Carbon::now()->subDay(),
                'received_by' => $nurse->id,
                'received_at' => Carbon::now()->subDay(),
                'status' => StoreIssue::STATUS_RECEIVED,
                'remarks' => 'Routine ward dressing sundries distributed.',
                'created_by' => $storeOfficer->id,
            ]
        );

        StoreIssueItem::updateOrCreate(
            ['store_issue_id' => $issue2->id, 'procurement_item_id' => $itemGloves->id],
            [
                'stock_batch_id' => $createdBatches['BAT-GLV-2026A']->id ?? 1,
                'item_name' => $itemGloves->name,
                'batch_number' => 'BAT-GLV-2026A',
                'expiry_date' => Carbon::now()->addMonths(24),
                'quantity_issued' => 10,
                'unit_cost' => 11500.00,
                'remarks' => '10 boxes examination gloves for nursing team',
            ]
        );

        // -------------------------------------------------------------
        // 13. Inter-Store Transfers (Central Store -> Sub-Stores)
        // -------------------------------------------------------------
        // Transfer 1: COMPLETED (Central Medical Store -> Main Pharmacy Store)
        $transfer1 = StoreTransfer::updateOrCreate(
            ['transfer_number' => 'TRF-2026-0001'],
            [
                'hospital_id' => $hospital->id,
                'from_store_id' => $centralStore->id,
                'to_store_id' => $pharmacyStore->id,
                'requested_by' => $pharmacist->id,
                'approved_by' => $storeManager->id,
                'approved_at' => Carbon::now()->subDays(6),
                'received_by' => $pharmacist->id,
                'received_at' => Carbon::now()->subDays(5),
                'status' => StoreTransfer::STATUS_COMPLETED,
                'notes' => 'Central stock replenishment for outpatient dispensing buffer.',
            ]
        );

        StoreTransferItem::updateOrCreate(
            ['store_transfer_id' => $transfer1->id, 'procurement_item_id' => $itemGloves->id],
            [
                'from_stock_batch_id' => $createdBatches['BAT-GLV-2026A']->id ?? 1,
                'to_stock_batch_id' => null,
                'item_name' => $itemGloves->name,
                'batch_number' => 'BAT-GLV-2026A',
                'expiry_date' => Carbon::now()->addMonths(24),
                'quantity_transferred' => 15,
                'remarks' => '15 boxes transferred to pharmacy storage',
            ]
        );

        // Transfer 2: IN_TRANSIT (Central Medical Store -> Laboratory Sub-Store)
        $transfer2 = StoreTransfer::updateOrCreate(
            ['transfer_number' => 'TRF-2026-0002'],
            [
                'hospital_id' => $hospital->id,
                'from_store_id' => $centralStore->id,
                'to_store_id' => $labStore->id,
                'requested_by' => $labTech->id,
                'approved_by' => $storeManager->id,
                'approved_at' => Carbon::now()->subHours(6),
                'status' => StoreTransfer::STATUS_IN_TRANSIT,
                'notes' => 'Rapid Diagnostic Test kits dispatched via internal porter; awaiting laboratory receipt verification.',
            ]
        );

        StoreTransferItem::updateOrCreate(
            ['store_transfer_id' => $transfer2->id, 'procurement_item_id' => $itemRdt->id],
            [
                'from_stock_batch_id' => $createdBatches['BAT-RDT-EXP35']->id ?? 1,
                'to_stock_batch_id' => null,
                'item_name' => $itemRdt->name,
                'batch_number' => 'BAT-RDT-EXP35',
                'expiry_date' => Carbon::now()->addDays(35),
                'quantity_transferred' => 10,
                'remarks' => '10 kits transferred under cold chain carrier',
            ]
        );

        // -------------------------------------------------------------
        // 14. Approval Histories (Unified Audit Trail)
        // -------------------------------------------------------------
        ApprovalHistory::firstOrCreate(
            [
                'approvable_type' => Indent::class,
                'approvable_id' => $indentApproved->id,
                'action' => 'approved',
            ],
            [
                'user_id' => $purchaseManager->id,
                'decision' => 'approved',
                'comments' => 'Approved as essential medication schedule requisition.',
                'created_at' => Carbon::now()->subDays(10),
            ]
        );

        ApprovalHistory::firstOrCreate(
            [
                'approvable_type' => PurchaseOrder::class,
                'approvable_id' => $poFullyReceived->id,
                'action' => 'approved',
            ],
            [
                'user_id' => $purchaseManager->id,
                'decision' => 'approved',
                'comments' => 'PO verified against tender contract and approved for placement.',
                'created_at' => Carbon::now()->subDays(17),
            ]
        );

        ApprovalHistory::firstOrCreate(
            [
                'approvable_type' => GoodsReceiptNote::class,
                'approvable_id' => $grnPosted->id,
                'action' => 'posted',
            ],
            [
                'user_id' => $storeManager->id,
                'decision' => 'posted',
                'comments' => 'Passed physical QC; posted to Central Medical Store ledger.',
                'created_at' => Carbon::now()->subDays(13),
            ]
        );
    }
}
