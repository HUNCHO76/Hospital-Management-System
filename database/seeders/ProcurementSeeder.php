<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Medication;
use App\Models\ProcurementItem;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class ProcurementSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Procurement Items (Global Catalog across categories)
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
                    'reorder_level' => 50,
                    'minimum_stock' => 20,
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
                'reorder_level' => 20,
                'minimum_stock' => 10,
                'estimated_unit_cost' => 12000.00,
            ],
            [
                'code' => 'CON-002',
                'name' => 'Syringes Disposable 5ml with Needle 21G',
                'category' => ProcurementItem::CATEGORY_CONSUMABLE,
                'unit_of_measure' => 'box',
                'specification' => 'Sterile disposable 5ml syringes with needle, 100 pcs',
                'reorder_level' => 15,
                'minimum_stock' => 5,
                'estimated_unit_cost' => 15000.00,
            ],
            [
                'code' => 'REA-001',
                'name' => 'Giemsa Stain Solution 500ml',
                'category' => ProcurementItem::CATEGORY_REAGENT,
                'unit_of_measure' => 'bottle',
                'specification' => 'Microscopy diagnostic Giemsa stain 500ml bottle',
                'reorder_level' => 5,
                'minimum_stock' => 2,
                'estimated_unit_cost' => 35000.00,
            ],
            [
                'code' => 'REA-002',
                'name' => 'Malaria Rapid Diagnostic Test (mRDT) Kits',
                'category' => ProcurementItem::CATEGORY_REAGENT,
                'unit_of_measure' => 'kit',
                'specification' => 'Pf/Pv antigen rapid detection kit, 25 tests/kit',
                'reorder_level' => 30,
                'minimum_stock' => 10,
                'estimated_unit_cost' => 45000.00,
            ],
            [
                'code' => 'RAD-001',
                'name' => 'Digital X-Ray Film 35x43cm',
                'category' => ProcurementItem::CATEGORY_RADIOLOGY,
                'unit_of_measure' => 'pack',
                'specification' => 'High resolution dry medical film, pack of 100',
                'reorder_level' => 10,
                'minimum_stock' => 3,
                'estimated_unit_cost' => 180000.00,
            ],
            [
                'code' => 'SUR-001',
                'name' => 'Suture Vicryl 2-0 with Curved Needle',
                'category' => ProcurementItem::CATEGORY_SURGICAL,
                'unit_of_measure' => 'box',
                'specification' => 'Synthetic absorbable surgical suture 2-0, box of 12',
                'reorder_level' => 10,
                'minimum_stock' => 4,
                'estimated_unit_cost' => 65000.00,
            ],
            [
                'code' => 'GEN-001',
                'name' => 'Hand Sanitizer Gel 5 Litres',
                'category' => ProcurementItem::CATEGORY_GENERAL,
                'unit_of_measure' => 'bottle',
                'specification' => '70% Isopropyl alcohol antiseptic gel, 5L canister',
                'reorder_level' => 8,
                'minimum_stock' => 3,
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

        // 2. Seed Stores & Vendors per Hospital
        $hospitals = Hospital::all();
        foreach ($hospitals as $hospital) {
            // Seed Stores
            Store::firstOrCreate(
                ['hospital_id' => $hospital->id, 'code' => 'CST-01'],
                [
                    'name' => 'Central Medical Store',
                    'type' => Store::TYPE_CENTRAL,
                    'is_default' => true,
                    'description' => 'Main receiving and central bulk storage for hospital supplies',
                    'status' => 'active',
                ]
            );

            $pharmacyDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Pharmacy%')->first();
            Store::firstOrCreate(
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

            $labDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Laboratory%')->first();
            Store::firstOrCreate(
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

            $radDept = Department::where('hospital_id', $hospital->id)->where('name', 'like', '%Radiology%')->first();
            Store::firstOrCreate(
                ['hospital_id' => $hospital->id, 'code' => 'RAD-01'],
                [
                    'department_id' => $radDept?->id,
                    'name' => 'Radiology Sub-Store',
                    'type' => Store::TYPE_RADIOLOGY,
                    'is_default' => false,
                    'description' => 'Imaging films and contrast materials sub-store',
                    'status' => 'active',
                ]
            );

            Store::firstOrCreate(
                ['hospital_id' => $hospital->id, 'code' => 'THE-01'],
                [
                    'name' => 'Operating Theatre Sub-Store',
                    'type' => Store::TYPE_THEATRE,
                    'is_default' => false,
                    'description' => 'Surgical kits, sutures, anaesthesia consumables',
                    'status' => 'active',
                ]
            );

            Store::firstOrCreate(
                ['hospital_id' => $hospital->id, 'code' => 'WRD-01'],
                [
                    'name' => 'Inpatient Ward Store',
                    'type' => Store::TYPE_WARD,
                    'is_default' => false,
                    'description' => 'Ward inventory for inpatient nursing supplies',
                    'status' => 'active',
                ]
            );

            Store::firstOrCreate(
                ['hospital_id' => $hospital->id, 'code' => 'GEN-01'],
                [
                    'name' => 'General Store',
                    'type' => Store::TYPE_SUB_STORE,
                    'is_default' => false,
                    'description' => 'Cleaning, stationery, and non-clinical sundries store',
                    'status' => 'active',
                ]
            );

            // Seed Vendors
            Vendor::firstOrCreate(
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

            Vendor::firstOrCreate(
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

            Vendor::firstOrCreate(
                ['hospital_id' => $hospital->id, 'code' => 'VND-BAHARI'],
                [
                    'name' => 'Bahari Pharmacy Supplies Ltd',
                    'contact_person' => 'Grace Mushi',
                    'email' => 'info@baharipharmacy.co.tz',
                    'phone' => '+255 754 112233',
                    'tax_id' => 'TIN-300-400-500 / VRN-40-009988-C',
                    'address' => 'Samora Avenue, Dar es Salaam',
                    'payment_terms' => 'Cash on Delivery',
                    'status' => 'active',
                ]
            );
        }
    }
}
