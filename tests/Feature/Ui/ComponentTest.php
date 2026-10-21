<?php

use App\Support\Format\Tone;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

test('shared controls keep Livewire attributes on their actual elements', function (): void {
    $html = Blade::render('<x-ui.input wire:model.live="reference" type="text" /><x-ui.button wire:click="issue">Issue</x-ui.button><x-ui.select wire:model="stage"><option>One</option></x-ui.select><x-ui.file wire:model="scan" accept=".pdf" />');

    expect($html)->toContain('<input', 'wire:model.live="reference"', 'wire:click="issue"', '<button', 'wire:model="stage"', '<select', 'wire:model="scan"', 'accept=".pdf"', 'type="file"');
});

test('badge tones, responsive table labels, and nested field errors render', function (): void {
    expect(Blade::render('<x-ui.field label="Reference" error="reference"><x-ui.input wire:model="reference" /></x-ui.field>'))
        ->toContain('wire:model="reference"');

    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['presence.7' => 'Choose a state']));
    // Pages receive the error bag as shared view data; components read it from there.
    view()->share('errors', $errors);
    $html = Blade::render('<x-ui.badge tone="red">Urgent</x-ui.badge><x-ui.table><tbody><tr><x-ui.td label="Case">42</x-ui.td></tr></tbody></x-ui.table><x-ui.field label="Presence" error="presence.7"><x-ui.select wire:model="presence.7"></x-ui.select></x-ui.field>');

    expect($html)->toContain('ui-badge-red', 'data-label="Case"', 'wire:model="presence.7"', 'Choose a state');
    expect(Tone::approval('approved'))->toBe('green');
});

test('Filament panels use the translated brand name', function (): void {
    app()->setLocale('en');
    expect(Filament::getPanel('admin')->getBrandName())->toBe('Displanery');

    app()->setLocale('ar');
    expect(Filament::getPanel('admin')->getBrandName())->toBe(__('Displanery'))
        ->and(Filament::getPanel('platform')->getBrandName())->toBe(__('Displanery'));
});
