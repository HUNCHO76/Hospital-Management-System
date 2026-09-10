<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

test('a user can be created', function () {
    $user = User::factory()->create(['UserName' => 'Jane Doe']);

    expect($user->UserName)->toBe('Jane Doe')
        ->and($user->exists)->toBeTrue();
});

test('user email must be unique', function () {
    User::factory()->create(['email' => 'duplicate@example.com']);

    expect(fn () => User::factory()->create(['email' => 'duplicate@example.com']))
        ->toThrow(QueryException::class);
});

test('a user can authenticate with a correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    expect(Hash::check('correct-password', $user->password))->toBeTrue()
        ->and(Hash::check('wrong-password', $user->password))->toBeFalse();
});
