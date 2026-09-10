<?php

namespace Database\Seeders;

use App\Models\Medication;
use Illuminate\Database\Seeder;

/**
 * A representative starter set of common medications — not a full
 * pharmacy formulary (which would have thousands of entries and isn't
 * available in this project). Gives the medication search something real to
 * query against; a production deployment would load the complete formulary
 * the same way, through this table.
 */
class MedicationSeeder extends Seeder
{
    /**
     * @var list<array{name: string, category: string}>
     */
    protected array $medications = [
        // Analgesics / antipyretics
        ['name' => 'Paracetamol', 'category' => 'Analgesic'],
        ['name' => 'Ibuprofen', 'category' => 'Analgesic'],
        ['name' => 'Diclofenac', 'category' => 'Analgesic'],
        ['name' => 'Aspirin', 'category' => 'Analgesic'],
        ['name' => 'Tramadol', 'category' => 'Analgesic'],

        // Antibiotics
        ['name' => 'Amoxicillin', 'category' => 'Antibiotic'],
        ['name' => 'Amoxicillin-Clavulanate', 'category' => 'Antibiotic'],
        ['name' => 'Ciprofloxacin', 'category' => 'Antibiotic'],
        ['name' => 'Doxycycline', 'category' => 'Antibiotic'],
        ['name' => 'Metronidazole', 'category' => 'Antibiotic'],
        ['name' => 'Azithromycin', 'category' => 'Antibiotic'],
        ['name' => 'Ceftriaxone', 'category' => 'Antibiotic'],
        ['name' => 'Erythromycin', 'category' => 'Antibiotic'],

        // Antimalarials
        ['name' => 'Artemether-Lumefantrine', 'category' => 'Antimalarial'],
        ['name' => 'Quinine', 'category' => 'Antimalarial'],
        ['name' => 'Sulfadoxine-Pyrimethamine', 'category' => 'Antimalarial'],

        // Antihypertensives
        ['name' => 'Amlodipine', 'category' => 'Antihypertensive'],
        ['name' => 'Lisinopril', 'category' => 'Antihypertensive'],
        ['name' => 'Losartan', 'category' => 'Antihypertensive'],
        ['name' => 'Hydrochlorothiazide', 'category' => 'Antihypertensive'],
        ['name' => 'Atenolol', 'category' => 'Antihypertensive'],

        // Antidiabetics
        ['name' => 'Metformin', 'category' => 'Antidiabetic'],
        ['name' => 'Glibenclamide', 'category' => 'Antidiabetic'],
        ['name' => 'Insulin (Soluble)', 'category' => 'Antidiabetic'],

        // Gastrointestinal
        ['name' => 'Omeprazole', 'category' => 'Gastrointestinal'],
        ['name' => 'Ranitidine', 'category' => 'Gastrointestinal'],
        ['name' => 'Metoclopramide', 'category' => 'Gastrointestinal'],
        ['name' => 'Oral Rehydration Salts', 'category' => 'Gastrointestinal'],
        ['name' => 'Loperamide', 'category' => 'Gastrointestinal'],

        // Respiratory / allergy
        ['name' => 'Salbutamol', 'category' => 'Respiratory'],
        ['name' => 'Cetirizine', 'category' => 'Antihistamine'],
        ['name' => 'Chlorpheniramine', 'category' => 'Antihistamine'],
        ['name' => 'Prednisolone', 'category' => 'Corticosteroid'],

        // Vitamins / supplements
        ['name' => 'Ferrous Sulphate', 'category' => 'Supplement'],
        ['name' => 'Folic Acid', 'category' => 'Supplement'],
        ['name' => 'Multivitamin', 'category' => 'Supplement'],
        ['name' => 'Vitamin C', 'category' => 'Supplement'],

        // Topical / wound care
        ['name' => 'Povidone-Iodine', 'category' => 'Antiseptic'],
        ['name' => 'Silver Sulfadiazine', 'category' => 'Antiseptic'],
        ['name' => 'Hydrocortisone Cream', 'category' => 'Topical'],
    ];

    /**
     * Seed the medication catalog. Idempotent via updateOrCreate() so it can
     * be run repeatedly without creating duplicates.
     */
    public function run(): void
    {
        foreach ($this->medications as $entry) {
            Medication::query()->updateOrCreate(
                ['name' => $entry['name']],
                ['category' => $entry['category'], 'status' => Medication::STATUS_ACTIVE],
            );
        }
    }
}
