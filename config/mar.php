<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Medication Administration Record (MAR) configuration
    |--------------------------------------------------------------------------
    |
    | `schedule_times` maps a prescription frequency to the clock times (24h,
    | HH:MM, facility local time) at which scheduled doses fall due on the
    | treatment sheet. These are ADMINISTRATIVE ward drug-round times that the
    | hospital must set/approve; they are not clinical advice. Frequencies
    | measured in hours (every_N_hours) are anchored to the order start time.
    | as_needed / stat / other produce no scheduled slots (record as PRN).
    |
    */

    'schedule_times' => [
        'once_daily' => ['08:00'],
        'twice_daily' => ['08:00', '20:00'],
        'three_times_daily' => ['08:00', '14:00', '20:00'],
        'four_times_daily' => ['06:00', '12:00', '18:00', '22:00'],
    ],

    'interval_hours' => [
        'every_4_hours' => 4,
        'every_6_hours' => 6,
        'every_8_hours' => 8,
        'every_12_hours' => 12,
    ],

    /*
    | A scheduled dose is "overdue" once this many minutes past its due time.
    */
    'overdue_after_minutes' => 60,

    /*
    | Administration time may not be in the future (beyond a small clock-skew
    | allowance) and may not be backdated further than this many minutes
    | without the 'inpatient.medications.correct' permission.
    */
    'future_tolerance_minutes' => 5,
    'backdate_limit_minutes' => 240,

    /*
    | When true, a dose cannot be recorded as given until the order item has a
    | non-cancelled dispensing record. Default false because stat/emergency
    | doses may legitimately precede dispensing — a hospital policy decision.
    */
    'require_dispensing_before_administration' => env('MAR_REQUIRE_DISPENSING', false),

];
