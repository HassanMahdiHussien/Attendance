<?php

namespace App\Services\Employees;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CsvReader
{
    private const HEADERS = [
        'name' => ['name', 'الاسم'],
        'employee_code' => ['employee_id', 'employee_code', 'رقم الموظف'],
        'department' => ['department', 'القسم'],
        'position' => ['position', 'المنصب'],
        'hire_date' => ['hire_date', 'تاريخ التعيين'],
        'branch' => ['branch', 'الفرع'],
        'email' => ['email', 'البريد'],
        'gender' => ['gender', 'الجنس'],
        'staff_category' => ['staff_category', 'AG HR Collar'],
        'legal_entity' => ['legal_entity', 'Company Code'],
    ];

    private const REQUIRED = ['name', 'hire_date', 'branch'];

    public function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || filesize($path) === 0 || filesize($path) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.file_empty_or_too_large')]);
        }

        $bytes = file_get_contents($path);
        $hash = hash('sha256', $bytes);
        $text = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = iconv('CP1256', 'UTF-8//IGNORE', $text);
        }
        if ($text === false || ! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.encoding_unreadable')]);
        }

        $candidates = [];
        foreach ([',', ';'] as $delimiter) {
            $records = $this->records($text, $delimiter);
            if ($records === []) {
                continue;
            }
            [$map, $notices, $errors] = $this->mapHeaders($records[0]);
            if ($errors === []) {
                $candidates[] = compact('records', 'map', 'notices');
            }
        }
        if (count($candidates) !== 1) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.headers_or_separator_invalid')]);
        }

        $candidate = $candidates[0];
        $records = array_slice($candidate['records'], 1);
        if (count($records) > 5000) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.row_limit')]);
        }
        if ($records === []) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.no_employees')]);
        }

        $rows = [];
        foreach ($records as $index => $record) {
            $values = [];
            foreach ($candidate['map'] as $field => $column) {
                $values[$field] = trim($record[$column] ?? '');
            }
            $rows[] = ['number' => $index + 2, 'values' => $values,
                'columns_valid' => count($record) === count($candidate['records'][0])];
        }

        return ['rows' => $rows, 'notices' => $candidate['notices'], 'hash' => $hash];
    }

    private function records(string $text, string $delimiter): array
    {
        if (! $this->hasValidQuotes($text, $delimiter)) {
            return [];
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $text);
        rewind($stream);
        $records = [];
        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($record === [null]) {
                continue;
            }
            $records[] = $record;
            if (count($records) > 5001) {
                break;
            }
        }
        fclose($stream);

        return $records;
    }

    private function hasValidQuotes(string $text, string $delimiter): bool
    {
        $state = 'start';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if ($i + 1 < $length && $text[$i + 1] === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }

                continue;
            }
            if ($char === $delimiter || $char === "\n" || $char === "\r") {
                $state = 'start';

                continue;
            }
            if ($char === '"') {
                if ($state !== 'start') {
                    return false;
                }
                $state = 'quoted';

                continue;
            }
            if ($state === 'closed') {
                return false;
            }
            $state = 'plain';
        }

        return $state !== 'quoted';
    }

    private function mapHeaders(array $headers): array
    {
        $map = [];
        $notices = [];
        $errors = [];
        foreach ($headers as $index => $header) {
            $header = $this->normalise($header ?? '');
            if ($header === '') {
                continue;
            }
            $field = null;
            foreach (self::HEADERS as $key => $aliases) {
                if (in_array($header, array_map($this->normalise(...), $aliases), true)) {
                    $field = $key;
                    break;
                }
            }
            if ($field === null) {
                $notices[] = $headers[$index];
            } elseif (isset($map[$field])) {
                $errors[] = $field;
            } else {
                $map[$field] = $index;
            }
        }
        foreach (self::REQUIRED as $required) {
            if (! isset($map[$required])) {
                $errors[] = $required;
            }
        }

        return [$map, $notices, $errors];
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }
}
