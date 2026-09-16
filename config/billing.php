<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing Currency
    |--------------------------------------------------------------------------
    |
    | Phase 7, Step 52: the single operational currency every PatientAccount/
    | Charge is stamped with at creation — centralized here rather than
    | hard-coded throughout the billing system (spec §10). St. Joseph DMI
    | operates in Tanzania, hence the TZS default; never assume this from
    | anywhere else in the codebase.
    |
    */

    'currency' => env('BILLING_CURRENCY', 'TZS'),

];
