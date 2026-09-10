<?php

use App\Support\Patients\PhoneNormalizer;

test('it generates the common Tanzanian phone number variants', function () {
    $variants = PhoneNormalizer::variants('0712345678');

    expect($variants)->toContain('0712345678')
        ->toContain('712345678')
        ->toContain('255712345678')
        ->toContain('+255712345678');
});

test('it produces the same variant set regardless of the input format', function () {
    $fromLocal = PhoneNormalizer::variants('0712345678');
    $fromInternational = PhoneNormalizer::variants('+255712345678');
    $fromBare = PhoneNormalizer::variants('712345678');

    // SORT_STRING avoids PHP's default numeric-string coercion, which would
    // otherwise treat '0712345678' and '712345678' as comparably "equal" and
    // produce an unstable order.
    sort($fromLocal, SORT_STRING);
    sort($fromInternational, SORT_STRING);
    sort($fromBare, SORT_STRING);

    expect($fromLocal)->toBe($fromInternational)->toBe($fromBare);
});

test('it strips spaces and dashes before normalizing', function () {
    expect(PhoneNormalizer::variants('071 234 5678'))->toContain('0712345678');
    expect(PhoneNormalizer::variants('071-234-5678'))->toContain('0712345678');
});

test('it returns an empty list for input with no digits', function () {
    expect(PhoneNormalizer::variants('abc'))->toBe([]);
});
