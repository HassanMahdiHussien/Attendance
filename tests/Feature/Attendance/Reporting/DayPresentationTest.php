<?php

use App\Services\Attendance\Reporting\DayPresentation;
use App\Services\Attendance\Reporting\DurationText;

beforeEach(function (): void {
    app()->setLocale('en');
});

function presentationRow(string $status): array
{
    $empty = ['gross' => 0, 'reconciled_by_leave' => 0, 'reconciled_by_credit' => 0, 'remaining' => 0];

    return ['status' => $status, 'work_date' => '2026-10-01', 'first_in' => null, 'last_out' => null,
        'minutes' => array_fill_keys(['absence', 'late', 'early', 'exit'], $empty),
        'observed' => ['presence' => 0, 'short' => 0], 'flags' => ['short_hours' => false]];
}

test('duration text reads hours, minutes, zero and negative balances in both languages', function (): void {
    foreach (['en' => ['4 h 14 min', '4 h', '9 min', '0 h', '-4 h 14 min'],
        'ar' => ['4 س 14 د', '4 س', '9 د', '0 س', '-4 س 14 د']] as $locale => $expected) {
        app()->setLocale($locale);
        expect(array_map(fn (int $minutes): string => app(DurationText::class)->format($minutes), [254, 240, 9, 0, -254]))->toBe($expected);
    }
});

test('absent days distinguish uncovered, partly covered and fully covered', function (): void {
    $row = presentationRow('absent');
    $row['minutes']['absence'] = ['gross' => 480, 'reconciled_by_leave' => 0, 'reconciled_by_credit' => 0, 'remaining' => 480];
    $presenter = app(DayPresentation::class);
    expect($presenter->from($row))->toMatchArray(['status_key' => 'absent', 'tone' => 'red', 'needs_review' => true,
        'problems' => ['Full day absent, no leave'], 'worked' => '—']);
    $row['minutes']['absence']['reconciled_by_leave'] = 240;
    $row['minutes']['absence']['remaining'] = 240;
    expect($presenter->from($row)['problems'])->toBe(['4 h covered by leave · 4 h not covered']);
    $row['minutes']['absence']['remaining'] = 0;
    expect($presenter->from($row))->toMatchArray(['status_key' => 'covered', 'tone' => 'green', 'needs_review' => false]);
});

test('present days show short time and covered time without changing the stored status', function (): void {
    $row = presentationRow('present');
    $row['first_in'] = '2026-10-01 09:00:00';
    $row['last_out'] = '2026-10-01 18:00:00';
    $row['observed']['presence'] = 540;
    $presenter = app(DayPresentation::class);
    expect($presenter->from($row))->toMatchArray(['status_key' => 'present', 'tone' => 'green', 'needs_review' => false, 'worked' => '9 h']);
    $row['minutes']['late'] = ['gross' => 254, 'reconciled_by_leave' => 0, 'reconciled_by_credit' => 0, 'remaining' => 254];
    $row['minutes']['early'] = ['gross' => 61, 'reconciled_by_leave' => 0, 'reconciled_by_credit' => 0, 'remaining' => 61];
    expect($presenter->from($row))->toMatchArray(['status_key' => 'short', 'tone' => 'amber', 'late' => true, 'early' => true,
        'problems' => ['Late 4 h 14 min', 'Left early 1 h 1 min']]);
    $row['minutes']['late']['remaining'] = 0;
    $row['minutes']['early']['remaining'] = 0;
    expect($presenter->from($row))->toMatchArray(['status_key' => 'covered', 'tone' => 'green', 'needs_review' => false]);
    $row['observed']['short'] = 10;
    expect($presenter->from($row))->toMatchArray(['status_key' => 'short', 'needs_review' => true]);
});

test('data states, pending days and non-working days keep distinct presentation', function (): void {
    $presenter = app(DayPresentation::class);
    $missing = presentationRow('missing_punch');
    $missing['first_in'] = '2026-10-01 09:00:00';
    expect($presenter->from($missing))->toMatchArray(['tone' => 'indigo', 'needs_review' => true,
        'problems' => ['Missing out punch'], 'worked' => 'Unknown']);
    $missing['first_in'] = null;
    $missing['last_out'] = '2026-10-01 18:00:00';
    expect($presenter->from($missing)['problems'])->toBe(['Missing in punch']);
    $missing['last_out'] = null;
    expect($presenter->from($missing)['problems'])->toBe(['Missing punch']);
    foreach (['shift_unresolved', 'pattern_missing'] as $status) {
        expect($presenter->from(presentationRow($status)))->toMatchArray(['tone' => 'indigo', 'needs_review' => true]);
    }
    expect($presenter->from(presentationRow('pending')))->toMatchArray(['tone' => 'gray', 'needs_review' => false,
        'problems' => [__('messages.reporting_ui.pending_note')], 'worked' => '—']);
    $waiting = presentationRow('pending');
    $waiting['inputs']['reason'] = 'no_device_for_employee';
    expect($presenter->from($waiting)['problems'])->toBe(['No device data for this person yet']);
    $waiting['inputs']['reason'] = 'branch_data_ended';
    expect($presenter->from($waiting)['problems'])->toBe(["This branch's devices have no data for this date"]);
    foreach (['rest_day', 'holiday', 'not_employed', 'device_outage'] as $status) {
        expect($presenter->from(presentationRow($status)))->toMatchArray(['tone' => 'gray', 'needs_review' => false]);
    }
    foreach (['rest_day_worked', 'holiday_worked'] as $status) {
        expect($presenter->from(presentationRow($status)))->toMatchArray(['tone' => 'green', 'needs_review' => false]);
    }
    expect($presenter->from(presentationRow('future_state')))->toMatchArray([
        'status_key' => 'future_state', 'label' => 'future_state', 'tone' => 'gray', 'needs_review' => false,
    ]);
});

test('day dates and status labels follow the interface language', function (): void {
    $presenter = app(DayPresentation::class);
    expect($presenter->from(presentationRow('pending')))->toMatchArray(['weekday' => 'Thursday', 'date' => '1 October']);
    app()->setLocale('ar');
    $arabic = $presenter->from(presentationRow('pending'));
    expect($arabic['weekday'])->toBe('الخميس')
        ->and($arabic['date'])->not->toBe('1 October')
        ->and($arabic['label'])->toBe(__('messages.office_ui.statuses.pending'));
});

test('out periods explain elapsed time without changing the day state', function (): void {
    $row = presentationRow('present');
    $row['first_in'] = '2026-10-01 08:29:00';
    $row['last_out'] = '2026-10-01 17:53:00';
    $row['observed']['presence'] = 499;
    $row['rest_minutes'] = 60;
    $row['exits'] = [['from' => '2026-10-01 12:50:00', 'to' => '2026-10-01 13:55:00', 'minutes' => 65]];
    $presenter = app(DayPresentation::class);

    expect($presenter->from($row))->toMatchArray([
        'status_key' => 'present', 'needs_review' => false, 'problems' => [],
        'out' => ['total' => '1 h 5 min', 'over_rest' => '5 min',
            'periods' => [['from' => '12:50', 'to' => '13:55', 'duration' => '1 h 5 min']]],
    ]);
    $row['exits'][0]['minutes'] = 60;
    expect($presenter->from($row)['out']['over_rest'])->toBeNull();
    $row['exits'] = [];
    expect($presenter->from($row)['out'])->toBeNull();
    $row['exits'][0] = ['from' => '2026-10-01 12:50:00', 'to' => '2026-10-01 13:55:00', 'minutes' => 65];
    $row['rest_minutes'] = null;
    expect($presenter->from($row)['out']['over_rest'])->toBeNull();
    $row['status'] = 'pending';
    expect($presenter->from($row)['out'])->toBeNull();

    app()->setLocale('ar');
    $row['status'] = 'present';
    expect($presenter->from($row)['out'])->toMatchArray(['total' => '1 س 5 د', 'over_rest' => null]);
    $row['rest_minutes'] = 60;
    expect($presenter->from($row)['out']['over_rest'])->toBe('5 د');
});
