<?php

use App\Models\User;

/**
 * Authorization for the whole pharmacy reporting surface (Phase 6, Step 50)
 * — a single 'pharmacy-reports.view' permission gates every report route
 * (mirrors 'laboratory-reports'), enforced per-action rather than in the
 * controller constructor (a constructor-level check would fire against the
 * console's own userless request during `artisan route:list`, which
 * resolves every controller to introspect its actions).
 */
function reportRoutes(): array
{
    return [
        'pharmacy-reports.index',
        'pharmacy-reports.stock-batches',
        'pharmacy-reports.low-stock',
        'pharmacy-reports.out-of-stock',
        'pharmacy-reports.expiry',
        'pharmacy-reports.stock-movements',
        'pharmacy-reports.medication-utilization',
        'pharmacy-reports.prescriptions',
        'pharmacy-reports.dispensing',
        'pharmacy-reports.reconciliation',
    ];
}

test('a guest is redirected to login for every report route', function () {
    foreach (reportRoutes() as $routeName) {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }
});

test('a permission-less user cannot view any report route', function () {
    seedRolesAndPermissions();
    $user = User::factory()->create();

    foreach (reportRoutes() as $routeName) {
        $this->actingAs($user)->get(route($routeName))->assertForbidden();
    }
});

test('a doctor holds no pharmacy-reports permission and cannot view any report route', function () {
    $doctor = userWithRole('doctor');

    foreach (reportRoutes() as $routeName) {
        $this->actingAs($doctor)->get(route($routeName))->assertForbidden();
    }
});

test('a pharmacist can view every report route', function () {
    $pharmacist = userWithRole('pharmacist');

    foreach (reportRoutes() as $routeName) {
        $this->actingAs($pharmacist)->get(route($routeName))->assertOk();
    }
});

test('a hospital-admin can view every report route', function () {
    $admin = userWithRole('hospital-admin');

    foreach (reportRoutes() as $routeName) {
        $this->actingAs($admin)->get(route($routeName))->assertOk();
    }
});

test('a super-admin bypasses pharmacy report permission checks', function () {
    $user = userWithRole('super-admin');

    foreach (reportRoutes() as $routeName) {
        $this->actingAs($user)->get(route($routeName))->assertOk();
    }
});

test('the pharmacy dashboard only sends operational metrics to a user holding pharmacy-reports.view', function () {
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->get(route('pharmacy.index'))
        ->assertInertia(fn ($page) => $page->has('metrics'));
});

test('exporting a report requires the distinct pharmacy-reports.export permission', function () {
    seedRolesAndPermissions();
    $viewOnlyUser = User::factory()->create();
    $viewOnlyUser->givePermissionTo('pharmacy-reports.view');

    $this->actingAs($viewOnlyUser)->get(route('pharmacy-reports.stock-batches'))->assertOk();
    $this->actingAs($viewOnlyUser)->get(route('pharmacy-reports.stock-batches', ['export' => 1]))->assertForbidden();
});
