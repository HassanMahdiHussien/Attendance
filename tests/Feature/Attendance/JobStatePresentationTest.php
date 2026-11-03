<?php

use App\Services\Attendance\Reporting\JobStatePresentation;

test('batch and export states share the same pill tones', function (): void {
    $presentation = new JobStatePresentation;

    expect($presentation->tone(null))->toBe('gray')
        ->and($presentation->tone('queued'))->toBe('gray')
        ->and($presentation->tone('processing'))->toBe('indigo')
        ->and($presentation->tone('running'))->toBe('indigo')
        ->and($presentation->tone('completed'))->toBe('green')
        ->and($presentation->tone('sent'))->toBe('green')
        ->and($presentation->tone('sending'))->toBe('indigo')
        ->and($presentation->tone('uncertain'))->toBe('indigo')
        ->and($presentation->tone('failed'))->toBe('red');
});
