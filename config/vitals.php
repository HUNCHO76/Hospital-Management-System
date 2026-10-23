<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vital Sign Ranges
    |--------------------------------------------------------------------------
    |
    | `min`/`max` are technical validity bounds — a value outside them is
    | rejected as a data-entry error (e.g. SpO2 = 250%), enforced by
    | StoreVitalSignRequest. `normal_low`/`normal_high` and
    | `critical_low`/`critical_high` are general adult reference ranges used
    | only to flag a technically valid value as low/high/critical for
    | display (VitalSignsService::evaluate()) — never to reject it, and
    | never presented as a diagnosis. These are NOT age/sex-adjusted; a
    | future module can override or extend them per patient context without
    | changing this shape.
    |
    */

    'ranges' => [
        'temperature_c' => [
            'min' => 20.0, 'max' => 45.0,
            'normal_low' => 36.1, 'normal_high' => 37.8,
            'critical_low' => 32.0, 'critical_high' => 40.0,
        ],
        'systolic_mmhg' => [
            'min' => 40, 'max' => 300,
            'normal_low' => 90, 'normal_high' => 140,
            'critical_low' => 70, 'critical_high' => 180,
        ],
        'diastolic_mmhg' => [
            'min' => 20, 'max' => 200,
            'normal_low' => 60, 'normal_high' => 90,
            'critical_low' => 40, 'critical_high' => 120,
        ],
        'pulse_bpm' => [
            'min' => 20, 'max' => 300,
            'normal_low' => 60, 'normal_high' => 100,
            'critical_low' => 40, 'critical_high' => 150,
        ],
        'respiratory_rate_bpm' => [
            'min' => 4, 'max' => 80,
            'normal_low' => 12, 'normal_high' => 20,
            'critical_low' => 8, 'critical_high' => 30,
        ],
        'oxygen_saturation_pct' => [
            'min' => 0, 'max' => 100,
            'normal_low' => 95, 'normal_high' => 100,
            'critical_low' => 90, 'critical_high' => 100,
        ],
        // Weight/height have technical bounds only — no physiologically
        // meaningful "normal" range to flag generically.
        'weight_kg' => ['min' => 0.1, 'max' => 500],
        'height_cm' => ['min' => 1, 'max' => 300],
        'pain_score' => ['min' => 0, 'max' => 10],
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation behaviour
    |--------------------------------------------------------------------------
    |
    | When true, an inpatient observation with any measurement in a configured
    | "critical" band automatically opens an ObservationEscalation (a request
    | for clinician review — never a diagnosis). The observation itself is
    | always saved. The bands above are generic adult reference values, NOT a
    | facility-approved protocol: replace them with the hospital's approved
    | early-warning/escalation thresholds before clinical use.
    |
    */

    'escalation' => [
        'auto_on_critical' => env('VITALS_AUTO_ESCALATE_ON_CRITICAL', true),
    ],

];
