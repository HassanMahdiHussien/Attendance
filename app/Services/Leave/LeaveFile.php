<?php

namespace App\Services\Leave;

use App\Services\Employees\SpreadsheetReader;
use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

class LeaveFile
{
    public const HEADERS = [
        'MyForm: Form Number', 'Owner Pers. No.', 'MyForm: Owner Name', 'Form Approver',
        'Owner Location', 'Owner Department', 'Leave Status', 'Leave Type', 'Leave Type (Other)',
        'Leave Starting Date', 'Leave End Date', 'Leave Duration (Work Days)',
        'Leave Duration (WorkDays, Approved)', 'MyForm: Created By', 'MyForm: Created Date',
        'Direct Manager', 'Leave Form Rejected',
    ];

    public const FIELDS = [
        'external_id', 'owner_code', 'owner_name', 'approver_name', 'owner_location',
        'owner_department', 'raw_status', 'type_name', 'other_type_name', 'start_date',
        'end_date', 'duration_days', 'approved_duration_days', 'created_by_name',
        'created_on', 'manager_name', 'rejected_on',
    ];

    public function __construct(private SpreadsheetReader $cells, private LeaveStatus $statuses) {}

    public function inspect(UploadedFile|string $file, ?string $name = null): array
    {
        [$path, $name] = $this->pathAndName($file, $name);
        foreach ($this->rawRows($path, $name) as $row) {
            $headers = array_map(fn (mixed $cell): string => trim((string) $cell), $row['cells']);
            if ($row['number'] !== 1 || count(array_intersect($headers, self::HEADERS)) < 8) {
                throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.header_missing')]);
            }

            return [
                'headers' => $headers, 'header_row' => 1, 'hash' => hash_file('sha256', $path),
                'snapshot_at' => $this->snapshotFromName($name)
                    ?? CarbonImmutable::createFromTimestamp(filemtime($path), 'Asia/Baghdad')->format('Y-m-d H:i:s'),
                'header_signature' => hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR)),
            ];
        }

        throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.header_missing')]);
    }

    public function suggest(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $index => $header) {
            $position = array_search(mb_strtolower(trim($header)), array_map('mb_strtolower', self::HEADERS), true);
            if ($position !== false) {
                $mapping[$index] = self::FIELDS[$position];
            }
        }

        return $mapping;
    }

    public function validateMapping(array $mapping, array $headers): array
    {
        $clean = [];
        foreach ($mapping as $index => $field) {
            if ($field === null || $field === '') {
                continue;
            }
            if (! ctype_digit((string) $index) || ! array_key_exists((int) $index, $headers)
                || ! in_array($field, self::FIELDS, true) || in_array($field, $clean, true)) {
                throw ValidationException::withMessages(['mapping' => __('messages.leave_import.mapping_invalid')]);
            }
            $clean[(int) $index] = $field;
        }
        foreach (['external_id', 'owner_code', 'raw_status', 'type_name', 'start_date', 'end_date', 'duration_days', 'rejected_on'] as $field) {
            if (! in_array($field, $clean, true)) {
                throw ValidationException::withMessages(['mapping' => __('messages.leave_import.mapping_invalid')]);
            }
        }
        ksort($clean);

        return $clean;
    }

    public function rows(UploadedFile|string $file, array $mapping, ?string $name = null): Generator
    {
        [$path, $name] = $this->pathAndName($file, $name);
        $sheet = $this->inspect($file, $name);
        $mapping = $this->validateMapping($mapping, $sheet['headers']);
        foreach ($this->rawRows($path, $name) as $row) {
            if ($row['number'] === 1 || $row['cells'] === [null]) {
                continue;
            }
            $raw = [];
            for ($index = 0; $index < max(count($sheet['headers']), count($row['cells'])); $index++) {
                $cell = $row['cells'][$index] ?? null;
                $raw[(string) $index] = $this->sourceText($cell);
            }
            $values = array_fill_keys(self::FIELDS, '');
            foreach ($mapping as $index => $field) {
                $cell = $row['cells'][$index] ?? null;
                $values[$field] = $this->cellText($cell, $field);
            }
            $errors = [];
            if (count($row['cells']) !== count($sheet['headers'])) {
                $errors[] = 'column_count';
            }
            foreach (['external_id', 'owner_code', 'raw_status', 'type_name'] as $field) {
                if ($values[$field] === '') {
                    $errors[] = $field.'_required';
                }
            }
            foreach ($values as $field => $value) {
                if (mb_strlen($value) > 255) {
                    $errors[] = $field.'_too_long';
                }
            }
            $start = $this->date($values['start_date']);
            $end = $this->date($values['end_date']);
            $rejected = $values['rejected_on'] === '' ? null : $this->date($values['rejected_on']);
            $created = $values['created_on'] === '' ? null : $this->date($values['created_on']);
            $latest = today('Asia/Baghdad')->addYear()->toDateString();
            $implausible = fn (?string $date): bool => $date !== null && ($date < '2000-01-01' || $date > $latest);
            if ($start === null || $end === null || $start < '2000-01-01' || $end > $latest || $end < $start
                || $implausible($created) || $implausible($rejected)
                || ($values['created_on'] !== '' && $created === null)
                || ($values['rejected_on'] !== '' && $rejected === null)) {
                $errors[] = 'invalid_dates';
            }
            $duration = $values['duration_days'] === '' ? null : $values['duration_days'];
            if ($duration !== null && (! preg_match('/^\d+(?:\.(?:0+|50*))?$/', $duration) || (float) $duration > 99999)) {
                $errors[] = 'invalid_duration';
                $duration = null;
            }
            if ($duration !== null && ((float) $duration <= 0
                || ($start !== null && $end !== null && $end >= $start
                    && (float) $duration > CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1))) {
                $errors[] = 'duration_mismatch';
            }
            $status = $this->statuses->derive($values['raw_status'], $rejected);
            if ($status['unknown']) {
                $errors[] = 'unknown_status';
            }
            if ($duration === null && $status['status'] !== 'draft') {
                $errors[] = 'missing_duration';
            }

            yield [
                'number' => $row['number'], 'raw' => $raw, 'values' => [
                    'external_id' => $values['external_id'], 'owner_code' => $values['owner_code'],
                    'approver_name' => $values['approver_name'] ?: null, 'raw_status' => $values['raw_status'],
                    'status' => $status['status'], 'type_name' => $values['type_name'],
                    'other_type_name' => $values['other_type_name'] ?: null,
                    'start_date' => $start, 'end_date' => $end, 'duration_days' => $duration,
                    'rejected_on' => $rejected, 'created_on' => $created,
                ], 'errors' => array_values(array_unique($errors)),
            ];
        }
    }

    public function snapshotFromName(string $name): ?string
    {
        if (! preg_match('/^IRAQ All Leave REPORT-(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})\.(?:xlsx|csv)$/i', $name, $matches)) {
            return null;
        }
        $value = implode('-', array_slice($matches, 1, 3)).' '.implode(':', array_slice($matches, 4, 3));
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'Asia/Baghdad');

            return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pathAndName(UploadedFile|string $file, ?string $name): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        if (! is_string($path) || ! is_file($path) || filesize($path) === 0 || filesize($path) > 50 * 1024 * 1024
            || mb_strlen($name) > 255 || ! in_array(mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.invalid_file')]);
        }

        return [$path, $name];
    }

    private function cellText(mixed $cell, string $field): string
    {
        if (in_array($field, ['external_id', 'owner_code'], true)) {
            return $this->cells->value($cell, 'employee_code');
        }
        if (in_array($field, ['start_date', 'end_date', 'rejected_on', 'created_on'], true)) {
            return $this->cells->value($cell, 'hire_date');
        }

        return $this->cells->value($cell, $field);
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
            if (checkdate((int) $matches[1], (int) $matches[2], (int) $matches[3])) {
                return sprintf('%04d-%02d-%02d', $matches[3], $matches[1], $matches[2]);
            }

            return null;
        }
        foreach (['n/j/Y', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value, 'Asia/Baghdad');
                if ($date !== false && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                // The next known source format may still parse the cell.
            }
        }

        return null;
    }

    private function sourceText(mixed $cell): string
    {
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('Y-m-d H:i:s');
        }
        if ($cell instanceof DateInterval) {
            return $cell->format('%d %H:%I:%S');
        }

        return (string) ($cell ?? '');
    }

    private function rawRows(string $path, string $name): Generator
    {
        if (mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx') {
            $reader = new Reader;
            $opened = false;
            try {
                $reader->open($path);
                $opened = true;
                foreach ($reader->getSheetIterator() as $sheet) {
                    $number = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        yield ['number' => ++$number, 'cells' => $row->toArray()];
                    }
                    break;
                }
            } catch (\Throwable) {
                throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.invalid_file')]);
            } finally {
                if ($opened) {
                    $reader->close();
                }
            }

            return;
        }

        $stream = fopen($path, 'rb');
        try {
            $first = fgets($stream) ?: '';
            $delimiter = count(str_getcsv($first, ';', '"', '')) > count(str_getcsv($first, ',', '"', '')) ? ';' : ',';
            rewind($stream);
            $number = 0;
            while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $number++;
                if ($number === 1 && isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
                }
                foreach ($cells as &$cell) {
                    if (is_string($cell) && ! mb_check_encoding($cell, 'UTF-8')) {
                        $cell = iconv('CP1256', 'UTF-8', $cell);
                        if ($cell === false) {
                            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.invalid_file')]);
                        }
                    }
                }
                unset($cell);
                if ($cells !== [null]) {
                    yield ['number' => $number, 'cells' => $cells];
                }
            }
        } finally {
            fclose($stream);
        }
    }
}
