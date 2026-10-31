<?php

namespace App\Services\Attendance;

use App\Services\Employees\CsvFormat;
use App\Services\Employees\SpreadsheetReader;
use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

class TransactionFile
{
    public const FIELDS = ['person_code', 'person_name', 'department_label', 'punch_date', 'punch_time', 'punch_state', 'serial_number', 'device_name', 'data_source'];

    private const HEADERS = ['Employee ID', 'First Name', 'Department', 'Date', 'Time', 'Punch State', 'Serial Number', 'Device Name', 'Data Sources'];

    public function __construct(private SpreadsheetReader $cells) {}

    public function inspect(UploadedFile|string $file, ?string $name = null): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        if (! is_string($path) || ! is_file($path) || filesize($path) === 0 || filesize($path) > config('attendance.max_file_bytes')) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.invalid_file')]);
        }
        if (mb_strlen($name) > 255 || ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.invalid_file')]);
        }
        foreach ($this->rawRows($path, $name) as $row) {
            $headers = array_map(SpreadsheetReader::label(...), $row['cells']);
            $matches = count(array_intersect(array_map('mb_strtolower', $headers), array_map('mb_strtolower', self::HEADERS)));
            if ($matches >= 4) {
                return ['headers' => $headers, 'header_row' => $row['number'], 'hash' => hash_file('sha256', $path), 'export_at' => $this->exportTimeFromName($name)];
            }
            if ($row['number'] >= 10) {
                break;
            }
        }

        throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.header_missing')]);
    }

    public function suggest(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $position = array_search(mb_strtolower(trim($header)), array_map('mb_strtolower', self::HEADERS), true);
            if ($position !== false) {
                $map[$index] = self::FIELDS[$position];
            }
        }

        return $map;
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
                throw ValidationException::withMessages(['mapping' => __('messages.attendance_import.mapping_invalid')]);
            }
            $clean[(int) $index] = $field;
        }
        if (count($clean) !== count(self::FIELDS)) {
            throw ValidationException::withMessages(['mapping' => __('messages.attendance_import.mapping_invalid')]);
        }
        ksort($clean);

        return $clean;
    }

    public function rows(UploadedFile|string $file, array $mapping, ?string $name = null): Generator
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        $sheet = $this->inspect($file, $name);
        $mapping = $this->validateMapping($mapping, $sheet['headers']);
        foreach ($this->rawRows($path, $name) as $row) {
            if ($row['number'] <= $sheet['header_row']) {
                continue;
            }
            $raw = array_fill_keys(self::FIELDS, '');
            $values = $raw;
            foreach ($mapping as $index => $field) {
                $cell = $row['cells'][$index] ?? null;
                $raw[$field] = $this->sourceText($cell, $field);
                $values[$field] = $this->text($cell, $field);
            }
            $errors = [];
            if (count($row['cells']) !== count($sheet['headers'])) {
                $errors[] = 'column_count';
            }
            foreach (['person_code', 'punch_date', 'punch_time', 'punch_state', 'serial_number'] as $field) {
                if ($values[$field] === '') {
                    $errors[] = $field.'_required';
                }
            }
            foreach ($values as $field => $value) {
                if (mb_strlen($value) > 255) {
                    $errors[] = $field.'_too_long';
                }
            }
            if (mb_strlen($values['serial_number']) > 128) {
                $errors[] = 'identifier_too_long';
            }
            $state = mb_strtolower($values['punch_state']);
            if ($state !== '' && ! in_array($state, ['check in', 'check out', '255'], true)) {
                $errors[] = 'punch_state_invalid';
            }
            $at = $this->punchedAt($values['punch_date'], $values['punch_time']);
            if ($at === null) {
                $errors[] = 'date_time_invalid';
            }

            yield ['number' => $row['number'], 'raw' => $raw, 'values' => $values, 'punched_at' => $at, 'errors' => array_values(array_unique($errors))];
        }
    }

    public function exportTimeFromName(string $name): ?string
    {
        if (! preg_match('/^Transaction_(\d{14})\.(?:xlsx|csv)$/i', $name, $match)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!YmdHis', $match[1], 'Asia/Baghdad');

            return $date !== false && $date->format('YmdHis') === $match[1] ? $date->format('Y-m-d H:i:s') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function punchedAt(string $date, string $time): ?string
    {
        $time = preg_match('/^\d{2}:\d{2}$/', $time) ? $time.':00' : $time;
        try {
            $at = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $date.' '.$time, 'Asia/Baghdad');

            return $at !== false && $at->format('Y-m-d H:i:s') === $date.' '.$time ? $at->format('Y-m-d H:i:s') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $cell, string $field): string
    {
        if ($field === 'person_code') {
            return $this->cells->value($cell, 'employee_code');
        }
        if ($field === 'punch_date') {
            return $this->cells->value($cell, 'hire_date');
        }
        if ($field === 'punch_time') {
            if ($cell instanceof DateTimeInterface) {
                return $cell->format('H:i');
            }
            if ($cell instanceof DateInterval) {
                return $cell->format('%H:%I');
            }
            if (is_float($cell) || is_int($cell)) {
                return $cell >= 0 && $cell < 1 ? gmdate('H:i', (int) round($cell * 86400)) : (string) $cell;
            }
        }

        return $this->cells->value($cell, $field);
    }

    private function sourceText(mixed $cell, string $field): string
    {
        if ($cell === null) {
            return '';
        }
        if ($cell instanceof DateTimeInterface) {
            return $cell->format($field === 'punch_time' ? 'H:i:s' : 'Y-m-d');
        }
        if ($cell instanceof DateInterval) {
            return $cell->format('%H:%I:%S');
        }

        return (string) $cell;
    }

    private function rawRows(string $path, string $name): Generator
    {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx') {
            $reader = new Reader;
            try {
                $reader->open($path);
                foreach ($reader->getSheetIterator() as $sheet) {
                    $number = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        $number++;
                        yield ['number' => $number, 'cells' => $row->toArray()];
                    }
                    break;
                }
            } finally {
                $reader->close();
            }

            return;
        }

        $stream = fopen($path, 'rb');
        try {
            $counts = [',' => 0, ';' => 0, "\t" => 0];
            for ($line = 0; $line < 3 && ($sample = fgets($stream)) !== false; $line++) {
                foreach (array_keys($counts) as $candidate) {
                    $counts[$candidate] = max($counts[$candidate], count(str_getcsv($sample, $candidate, '"', '')));
                }
            }
            $delimiter = array_search(max($counts), $counts, true);
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
                            throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.invalid_file')]);
                        }
                    }
                }
                unset($cell);
                if ($number <= 10) {
                    CsvFormat::assertReadableHeader($cells);
                }
                if ($cells !== [null]) {
                    yield ['number' => $number, 'cells' => $cells];
                }
            }
        } finally {
            fclose($stream);
        }
    }
}
