<?php

use App\Support\Format\Ui;
use App\Support\Text\ArabicText;
use Tests\TestCase;

uses(TestCase::class);

it('uses Western digits in either interface language', function (): void {
    app()->setLocale('ar');
    expect(Ui::number('0142 - 2026'))->toBe('0142 - 2026');
    expect(Ui::number('٠١٤٢'))->toBe('0142');
    expect(Ui::date('2026-06-18'))->toContain('18', '2026');
    app()->setLocale('en');
    expect(Ui::number(15))->toBe('15');
});

it('converts both Arabic digit ranges without changing legal prose', function (): void {
    expect(ArabicText::westernDigits('المادة ١٢ و ۳۴'))->toBe('المادة 12 و 34');
});
