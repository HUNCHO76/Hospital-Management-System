<?php

use App\Models\Hospital;

test('direct access to unauthorized module routes returns forbidden 403', function () {
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get('/nhif/claims/monthly')->assertForbidden();
    $this->actingAs($doctor)->get('/procurement')->assertForbidden();
    $this->actingAs($doctor)->get('/stores')->assertForbidden();
    $this->actingAs($doctor)->get('/billing')->assertForbidden();
    $this->actingAs($doctor)->get('/staff')->assertForbidden();
});

test('store officer cannot access clinical encounters or finance modules directly', function () {
    $storeOfficer = userWithRole('store-officer');

    $this->actingAs($storeOfficer)->get('/clinical-encounters')->assertForbidden();
    $this->actingAs($storeOfficer)->get('/billing')->assertForbidden();
    $this->actingAs($storeOfficer)->get('/nhif/claims/monthly')->assertForbidden();
    $this->actingAs($storeOfficer)->get('/stores')->assertOk();
});

test('hospital admin can access monthly claims and admin modules', function () {
    $hospital = Hospital::factory()->create([
        'code' => '01141',
    ]);

    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->get('/nhif/claims/monthly')->assertOk();
    $this->actingAs($admin)->get('/staff')->assertOk();
    $this->actingAs($admin)->get('/departments')->assertOk();
});
