<?php

namespace App\Services\NHIF;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryTest;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\PatientInsurance;
use App\Models\PrescriptionOrderItem;
use Illuminate\Http\Request;

/**
 * Prepares and submits NHIF pre-approval for laboratory and medication
 * items ordered within a clinical encounter.
 *
 * Uses LaboratoryTest.nhif_code and Medication.nhif_code directly
 * (not NhifServiceMapping) and fetches prices from the NHIF
 * GetPricePackageByItemCode API.
 */
class NhifEncounterPreApprovalService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected NhifPreApprovalSessionService $sessions,
    ) {}

    /**
     * Collect lab/medication items from the encounter that have NHIF codes
     * and don't yet have a pre-approval session item, then fetch their
     * NHIF prices.
     *
     * @return array{lab_items: list<array>, medication_items: list<array>}
     */
    public function prepareItems(ClinicalEncounter $encounter, PatientInsurance $insurance): array
    {
        $facilityCode = $insurance->nhif_facility_code;
        if (blank($facilityCode)) {
            return ['lab_items' => [], 'medication_items' => []];
        }

        $this->autoMapUnmappedLabTests($encounter, $insurance);
        $this->autoMapUnmappedMedications($encounter, $insurance);

        $existingSourceKeys = $this->existingSessionItemKeys($encounter->id);

        $labItems = $this->collectLabItems($encounter, $existingSourceKeys);
        $medItems = $this->collectMedicationItems($encounter, $existingSourceKeys);

        $allCodes = array_unique(array_merge(
            array_column($labItems, 'nhif_item_code'),
            array_column($medItems, 'nhif_item_code'),
        ));

        if (empty($allCodes)) {
            return ['lab_items' => [], 'medication_items' => []];
        }

        $prices = $this->client->fetchBulkPrices($facilityCode, $allCodes);

        foreach ($labItems as &$item) {
            $item['unit_price'] = $prices[$item['nhif_item_code']] ?? null;
        }
        unset($item);

        foreach ($medItems as &$item) {
            $item['unit_price'] = $prices[$item['nhif_item_code']] ?? null;
        }
        unset($item);

        return ['lab_items' => $labItems, 'medication_items' => $medItems];
    }

    /**
     * Create and immediately submit a pre-approval session from selected
     * lab order items and/or prescription order items.
     *
     * Prices are re-fetched server-side — never trusted from the request.
     *
     * @param  list<int>  $labOrderItemIds
     * @param  list<int>  $prescriptionOrderItemIds
     */
    public function createAndSubmitFromSelection(
        PatientInsurance $insurance,
        ClinicalEncounter $encounter,
        array $labOrderItemIds,
        array $prescriptionOrderItemIds,
        Request $request,
        ?string $clinicalNotes = null,
        ?string $practitionerNo = null,
    ): NhifPreApprovalSession {
        if (blank($practitionerNo)) {
             $practitionerNo = $request->user()->staff?->practitioner?->nhif_practitioner_no;
        }

        if (blank($practitionerNo)) {
            $practitionerNo = \App\Models\Practitioner::query()
                ->whereHas('staff', fn ($q) => $q->where('hospital_id', $encounter->hospital_id))
                ->where('status', \App\Models\Practitioner::STATUS_ACTIVE)
                ->whereNotNull('nhif_practitioner_no')
                ->where('nhif_practitioner_no', '!=', '')
                ->value('nhif_practitioner_no');
        }

        $facilityCode = $insurance->nhif_facility_code ?? '';

        $sessionItems = [];

        if (! empty($labOrderItemIds)) {
            $labItems = LaboratoryOrderItem::query()
                ->whereIn('id', $labOrderItemIds)
                ->whereHas('laboratoryOrder', fn ($q) => $q->where('clinical_encounter_id', $encounter->id))
                ->with('laboratoryTest:id,name,code,nhif_code')
                ->get();

            foreach ($labItems as $item) {
                $nhifCode = $item->laboratoryTest?->nhif_code;
                if (blank($nhifCode)) {
                    continue;
                }

                $sessionItems[] = [
                    'source_type' => NhifPreApprovalSessionItem::SOURCE_LAB,
                    'source_id' => $item->id,
                    'nhif_item_code' => $nhifCode,
                    'nhif_item_name' => $item->test_name,
                    'quantity' => 1,
                    'unit_price' => 0,
                    '_fetch_price_code' => $nhifCode,
                ];
            }
        }

        if (! empty($prescriptionOrderItemIds)) {
            $medItems = PrescriptionOrderItem::query()
                ->whereIn('id', $prescriptionOrderItemIds)
                ->whereHas('prescriptionOrder', fn ($q) => $q->where('clinical_encounter_id', $encounter->id))
                ->with('medication:id,generic_name,code,nhif_code')
                ->get();

            foreach ($medItems as $item) {
                $nhifCode = $item->medication?->nhif_code;
                if (blank($nhifCode)) {
                    continue;
                }

                $sessionItems[] = [
                    'source_type' => NhifPreApprovalSessionItem::SOURCE_MEDICATION,
                    'source_id' => $item->id,
                    'nhif_item_code' => $nhifCode,
                    'nhif_item_name' => $item->medication_name_snapshot,
                    'quantity' => $item->quantity ?? 1,
                    'unit_price' => 0,
                    '_fetch_price_code' => $nhifCode,
                ];
            }
        }

        if (! empty($facilityCode)) {
            $codesToFetch = array_unique(array_column($sessionItems, '_fetch_price_code'));
            $prices = $this->client->fetchBulkPrices($facilityCode, $codesToFetch);

            foreach ($sessionItems as &$si) {
                $si['unit_price'] = $prices[$si['_fetch_price_code']] ?? 0;
                unset($si['_fetch_price_code']);
            }
            unset($si);
        } else {
            foreach ($sessionItems as &$si) {
                unset($si['_fetch_price_code']);
            }
            unset($si);
        }

        $session = $this->sessions->create(
            $insurance,
            $encounter,
            $sessionItems,
            $request,
            $practitionerNo,
            $clinicalNotes,
        );

        return $this->sessions->submit($session, $request);
    }

    /**
     * Keys of items that already belong to a non-cancelled pre-approval session
     * for this encounter, so we don't show them as pending again.
     *
     * @return array<string, true>
     */
    private function existingSessionItemKeys(int $encounterId): array
    {
        $items = NhifPreApprovalSessionItem::query()
            ->whereHas('session', fn ($q) => $q
                ->where('clinical_encounter_id', $encounterId)
                ->where('status', '!=', NhifPreApprovalSession::STATUS_CANCELLED))
            ->get(['source_type', 'source_id']);

        $keys = [];
        foreach ($items as $item) {
            if ($item->source_type && $item->source_id) {
                $keys["{$item->source_type}:{$item->source_id}"] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $existingKeys
     * @return list<array{source_type: string, source_id: int, nhif_item_code: string, nhif_item_name: string, unit_price: null}>
     */
    private function collectLabItems(ClinicalEncounter $encounter, array $existingKeys): array
    {
        $encounter->loadMissing('laboratoryOrders.items.laboratoryTest');
        $items = [];

        foreach ($encounter->laboratoryOrders as $order) {
            foreach ($order->items as $item) {
                if ($item->status === 'cancelled') {
                    continue;
                }

                $nhifCode = $item->laboratoryTest?->nhif_code;
                if (blank($nhifCode)) {
                    continue;
                }

                $key = NhifPreApprovalSessionItem::SOURCE_LAB.":{$item->id}";
                if (isset($existingKeys[$key])) {
                    continue;
                }

                $items[] = [
                    'source_type' => NhifPreApprovalSessionItem::SOURCE_LAB,
                    'source_id' => $item->id,
                    'nhif_item_code' => $nhifCode,
                    'nhif_item_name' => $item->test_name,
                    'quantity' => 1,
                    'unit_price' => null,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, true>  $existingKeys
     * @return list<array{source_type: string, source_id: int, nhif_item_code: string, nhif_item_name: string, quantity: int, unit_price: null, needs_mapping?: bool, medication_id?: int}>
     */
    private function collectMedicationItems(ClinicalEncounter $encounter, array $existingKeys): array
    {
        $encounter->loadMissing('prescriptionOrders.items.medication');
        $items = [];

        foreach ($encounter->prescriptionOrders as $order) {
            foreach ($order->items as $item) {
                if ($item->status === 'cancelled') {
                    continue;
                }

                $key = NhifPreApprovalSessionItem::SOURCE_MEDICATION.":{$item->id}";
                if (isset($existingKeys[$key])) {
                    continue;
                }

                $nhifCode = $item->medication?->nhif_code;

                $items[] = [
                    'source_type' => NhifPreApprovalSessionItem::SOURCE_MEDICATION,
                    'source_id' => $item->id,
                    'nhif_item_code' => $nhifCode ?? '',
                    'nhif_item_name' => $item->medication_name_snapshot,
                    'quantity' => $item->quantity ?? 1,
                    'unit_price' => null,
                    'needs_mapping' => blank($nhifCode),
                    'medication_id' => $item->medication_id,
                ];
            }
        }

        return $items;
    }

    /**
     * Best-effort auto-mapping: for each medication in the encounter that
     * lacks an nhif_code, try to find a matching NHIF catalog item by
     * generic name prefix match and set the code. Mirrors
     * autoMapUnmappedLabTests() for prescriptions.
     */
    private function autoMapUnmappedMedications(ClinicalEncounter $encounter, PatientInsurance $insurance): void
    {
        if (! $insurance->nhif_scheme_id) {
            return;
        }

        $encounter->loadMissing('prescriptionOrders.items.medication');

        $unmappedMedications = collect();
        foreach ($encounter->prescriptionOrders as $order) {
            foreach ($order->items as $item) {
                $medication = $item->medication;
                if ($medication && blank($medication->nhif_code) && ! $unmappedMedications->has($medication->id)) {
                    $unmappedMedications->put($medication->id, $medication);
                }
            }
        }

        if ($unmappedMedications->isEmpty()) {
            return;
        }

        foreach ($unmappedMedications as $medication) {
            $name = $medication->generic_name;

            $match = NhifSchemeService::catalog()
                ->where('type_name', 'Medicine and Consumables')
                ->where('scheme_id', $insurance->nhif_scheme_id)
                ->where('item_name', 'like', "{$name}%")
                ->selectRaw('DISTINCT item_code, item_name')
                ->first();

            if (! $match) {
                $firstWord = explode(' ', $name)[0] ?? '';
                if (mb_strlen($firstWord) >= 3) {
                    $match = NhifSchemeService::catalog()
                        ->where('type_name', 'Medicine and Consumables')
                        ->where('scheme_id', $insurance->nhif_scheme_id)
                        ->where('item_name', 'like', "{$firstWord}%")
                        ->selectRaw('DISTINCT item_code, item_name')
                        ->first();
                }
            }

            if ($match) {
                $medication->update(['nhif_code' => $match->item_code]);
            }
        }
    }

    /**
     * Best-effort auto-mapping: for each lab test in the encounter that
     * lacks an nhif_code, try to find a matching NHIF catalog item by
     * exact or base-name match and set the code. This bridges the gap
     * for tests created before the Item Mapping page existed.
     */
    private function autoMapUnmappedLabTests(ClinicalEncounter $encounter, PatientInsurance $insurance): void
    {
        if (! $insurance->nhif_scheme_id) {
            return;
        }

        $encounter->loadMissing('laboratoryOrders.items.laboratoryTest');

        $unmappedTests = collect();
        foreach ($encounter->laboratoryOrders as $order) {
            foreach ($order->items as $item) {
                $test = $item->laboratoryTest;
                if ($test && blank($test->nhif_code) && ! $unmappedTests->has($test->id)) {
                    $unmappedTests->put($test->id, $test);
                }
            }
        }

        if ($unmappedTests->isEmpty()) {
            return;
        }

        foreach ($unmappedTests as $test) {
            $match = NhifSchemeService::catalog()
                ->where('item_type_id', 5)
                ->where('scheme_id', $insurance->nhif_scheme_id)
                ->where('item_name', 'like', "{$test->name}%")
                ->selectRaw('DISTINCT item_code, item_name')
                ->first();

            if ($match) {
                $test->update(['nhif_code' => $match->item_code]);
            }
        }
    }
}
