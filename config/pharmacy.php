<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allow Self-Verification of Prescriptions
    |--------------------------------------------------------------------------
    |
    | Phase 6, Step 47: hospital policy on whether the prescriber who wrote a
    | prescription may also verify it. Independent verification is the
    | default and recommended setting — mirrors 'radiology.
    | allow_self_verification' exactly; only a hospital that explicitly
    | permits self-verification should set this to true. Never hard-coded —
    | read by PrescriptionOrderPolicy/PrescriptionVerificationService at the
    | point of review/verify/return.
    |
    */

    'allow_self_verification' => env('PHARMACY_ALLOW_SELF_VERIFICATION', false),

    /*
    |--------------------------------------------------------------------------
    | Stock Expiry Warning Period
    |--------------------------------------------------------------------------
    |
    | Phase 6, Step 49: the number of days ahead of a batch's expiry_date that
    | it is surfaced as "expiring soon" (spec §8) — never hard-coded, read by
    | StockService/StockSearchService at the point of classification.
    |
    */

    'stock_expiry_warning_days' => env('PHARMACY_STOCK_EXPIRY_WARNING_DAYS', 90),

];
