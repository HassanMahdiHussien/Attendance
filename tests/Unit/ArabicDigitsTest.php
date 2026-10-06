<?php

use App\Support\Format\ArabicDigits;

it('converts every Western digit to its Arabic-Indic form as valid UTF-8', function (): void {
    $converted = ArabicDigits::convert('Case 0142 - 2026, 9876543210');

    expect($converted)->toBe('Case ٠١٤٢ - ٢٠٢٦, ٩٨٧٦٥٤٣٢١٠')
        ->and(mb_check_encoding($converted, 'UTF-8'))->toBeTrue()
        ->and(ArabicDigits::convert(15))->toBe('١٥');
});
