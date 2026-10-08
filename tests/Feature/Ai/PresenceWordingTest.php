<?php

use App\Services\Cases\PresenceWording;

test('presence labels describe only the appearance of a box', function (): void {
    app()->setLocale('en');
    foreach (['appears_filled', 'appears_empty', 'unclear'] as $result) {
        $label = PresenceWording::suggestion($result);
        expect($label)->toContain('signature box');
        expect(mb_strtolower($label))->not->toMatch('/verified|authentic|genuine|identity|موثّق|أصلي/u');
    }
});

test('presence views and response labels make no signer identity claim', function (): void {
    $views = collect([
        resource_path('views/livewire/cases/signed-scan.blade.php'),
        resource_path('views/livewire/cases/show-case.blade.php'),
    ])->merge(glob(app_path('Notifications/*.php')))
        ->map(fn ($path) => file_get_contents($path))->implode("\n");
    $labels = json_encode([
        'suggestions' => array_map(fn ($value) => PresenceWording::suggestion($value),
            ['appears_filled', 'appears_empty', 'unclear']),
        'choices' => [PresenceWording::choice('filled'), PresenceWording::choice('empty')],
    ], JSON_UNESCAPED_UNICODE);
    expect($views.$labels)->not->toMatch('/التوقيع (?:موثّق|أصلي|تم التحقق منه)|verified signature|authentic signature|signer identity confirmed/ui');
});
