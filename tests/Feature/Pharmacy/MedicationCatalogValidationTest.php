<?php

test('a duplicate medication code is rejected', function () {
    $admin = userWithRole('hospital-admin');
    activeMedication(['code' => 'DUP-CODE']);

    $this->actingAs($admin)->post(route('medications.store'), [
        'code' => 'DUP-CODE',
        'generic_name' => 'Duplicate Attempt',
    ])->assertSessionHasErrors('code');

    $this->assertDatabaseCount('medications', 1);
});

test('a missing medication generic name is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('medications.store'), ['code' => 'NONAME'])->assertSessionHasErrors('generic_name');
});

test('an invalid form is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('medications.store'), [
        'code' => 'BADFORM',
        'generic_name' => 'Bad Form',
        'form' => 'not_a_real_form',
    ])->assertSessionHasErrors('form');
});

test('an invalid route is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('medications.store'), [
        'code' => 'BADROUTE',
        'generic_name' => 'Bad Route',
        'route' => 'not_a_real_route',
    ])->assertSessionHasErrors('route');
});

test('a negative price is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('medications.store'), [
        'code' => 'NEGPRICE',
        'generic_name' => 'Negative Price',
        'price' => -10,
    ])->assertSessionHasErrors('price');
});
