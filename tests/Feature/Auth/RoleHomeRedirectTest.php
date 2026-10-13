<?php

use App\Support\Auth\RoleHomeResolver;

dataset('role homes', [
    'receptionist' => ['receptionist', '/reception'],
    'doctor' => ['doctor', '/clinical-encounters'],
    'nurse' => ['nurse', '/clinical-encounters'],
    'laboratory technician' => ['laboratory-technician', '/laboratory'],
    'radiology technician' => ['radiology-technician', '/radiology'],
    'pharmacist' => ['pharmacist', '/pharmacy'],
    'accountant' => ['accountant', '/billing'],
    'purchase officer' => ['purchase-officer', '/procurement'],
    'purchase manager' => ['purchase-manager', '/procurement'],
    'store officer' => ['store-officer', '/stores'],
    'store manager' => ['store-manager', '/stores'],
    'hospital admin' => ['hospital-admin', '/dashboard'],
    'manager' => ['Manager', '/dashboard'],
    'super admin' => ['super-admin', '/dashboard'],
]);

test('users land on their role home page after login', function (string $role, string $expected) {
    $user = userWithRole($role);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($expected);

    expect(RoleHomeResolver::for($user))->toBe($expected);
})->with('role homes');

test('login ignores a previously intended page the user cannot access', function () {
    $user = userWithRole('laboratory-technician');

    $this->get('/audit-logs')->assertRedirect('/login');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/laboratory');
});
