<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allow Self-Verification of Radiology Reports
    |--------------------------------------------------------------------------
    |
    | Phase 5, Step 43, Part 5: hospital policy on whether a reporting
    | practitioner may verify their own radiology report. Independent
    | verification is the default and recommended setting; only a hospital
    | that explicitly permits self-verification should set this to true.
    | Never hard-coded — read by RadiologyReportPolicy/RadiologyReportService
    | at the point of review/verify/return.
    |
    */

    'allow_self_verification' => env('RADIOLOGY_ALLOW_SELF_VERIFICATION', false),

];
