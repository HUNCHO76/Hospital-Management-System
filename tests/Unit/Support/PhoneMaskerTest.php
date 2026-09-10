<?php

use App\Support\Patients\PhoneMasker;

test('it masks the middle digits of a standard phone number', function () {
    expect(PhoneMasker::mask('0712345678'))->toBe('0712****78');
});

test('it keeps only the last two digits visible for a short number', function () {
    expect(PhoneMasker::mask('12345'))->toBe('***45');
});

test('it returns null and empty string input unchanged', function () {
    expect(PhoneMasker::mask(null))->toBeNull();
    expect(PhoneMasker::mask(''))->toBe('');
});
