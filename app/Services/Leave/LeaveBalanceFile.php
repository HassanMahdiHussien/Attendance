<?php

namespace App\Services\Leave;

use DOMDocument;
use DOMXPath;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use XMLReader;
use ZipArchive;

class LeaveBalanceFile
{
    public const FIELDS = [
        'employee_code', 'remaining_days', 'carried_over_days', 'entitlement_days', 'used_days',
        'used_01', 'used_02', 'used_03', 'used_04', 'used_05', 'used_06',
        'used_07', 'used_08', 'used_09', 'used_10', 'used_11', 'used_12',
    ];

    public function inspect(UploadedFile|string $file, ?string $name = null): array
    {
        [$path, $name] = $this->pathAndName($file, $name);
        foreach ($this->rawRows($path, $name) as $row) {
            $headers = array_map(fn ($cell): string => trim((string) $cell->getValue()), $row['cells']);
            $found = collect($headers)->contains(fn (string $header): bool => $this->headerKey($header) === 'totalremainingannualleavedays');
            if ($found) {
                // The company report has up to two label rows under the header (month names, then PLAN/ACT);
                // their labels are joined per column and the rows are skipped as data.
                $subheaders = [];
                $structuralRows = [];
                foreach ($this->rawRows($path, $name) as $candidate) {
                    if ($candidate['number'] <= $row['number']) {
                        continue;
                    }
                    if ($candidate['number'] > $row['number'] + 2 || ! $this->isLabelRow($candidate['cells'])) {
                        break;
                    }
                    $structuralRows[] = $candidate['number'];
                    foreach ($candidate['cells'] as $index => $cell) {
                        $label = trim((string) $cell->getValue());
                        if ($label !== '') {
                            $subheaders[$index] = trim(($subheaders[$index] ?? '').' '.$label);
                        }
                    }
                }
                $width = max(count($headers), $subheaders === [] ? 0 : max(array_keys($subheaders)) + 1);
                $subheaders = array_map(fn (int $index): string => $subheaders[$index] ?? '', range(0, max(0, $width - 1)));
                $signature = hash('sha256', json_encode([$headers, $subheaders], JSON_THROW_ON_ERROR));

                return [
                    'headers' => $headers, 'subheaders' => $subheaders, 'header_row' => $row['number'],
                    'label_rows' => $structuralRows,
                    'header_signature' => $signature, 'hash' => hash_file('sha256', $path),
                    'suggested_year' => $this->suggestedYear($headers),
                ];
            }
            if ($row['number'] >= 50) {
                break;
            }
        }

        throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.header_missing')]);
    }

    public function suggest(array $headers, array $subheaders = []): array
    {
        $mapping = [];
        foreach ($headers as $index => $header) {
            $name = $this->normalized($header);
            $key = $this->headerKey($header);
            $field = match (true) {
                $key === 'pers.no.', $key === 'personnelnumber' => 'employee_code',
                $key === 'totalremainingannualleavedays' => 'remaining_days',
                str_starts_with($key, 'remainingfrom') => 'carried_over_days',
                str_contains($key, 'entiteledin'), str_contains($key, 'entitledin') => 'entitlement_days',
                str_starts_with($key, 'ytdactused') => 'used_days',
                default => null,
            };
            if ($field === null && preg_match('/\bact\b/', $this->normalized($subheaders[$index] ?? '')) === 1) {
                // The company report puts the month name over PLAN and leaves it blank over ACT.
                $month = $this->month($name) ?? $this->month($this->normalized($subheaders[$index] ?? ''))
                    ?? $this->month($this->normalized($headers[$index - 1] ?? ''))
                    ?? $this->month($this->normalized($subheaders[$index - 1] ?? ''));
                $field = $month === null ? null : sprintf('used_%02d', $month);
            }
            if ($field !== null && ! in_array($field, $mapping, true)) {
                $mapping[$index] = $field;
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
                throw ValidationException::withMessages(['mapping' => __('messages.leave_balance.mapping_invalid')]);
            }
            $clean[(int) $index] = $field;
        }
        if (! in_array('employee_code', $clean, true) || ! in_array('remaining_days', $clean, true)) {
            throw ValidationException::withMessages(['mapping' => __('messages.leave_balance.mapping_invalid')]);
        }
        ksort($clean);

        return $clean;
    }

    public function rows(UploadedFile|string $file, array $mapping, ?string $name = null, ?int $codeLength = null): Generator
    {
        [$path, $name] = $this->pathAndName($file, $name);
        $sheet = $this->inspect($path, $name);
        $mapping = $this->validateMapping($mapping, $sheet['headers']);
        $missingFormulaValues = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx'
            ? $this->missingFormulaValues($path) : [];
        foreach ($this->rawRows($path, $name) as $row) {
            if ($row['number'] <= $sheet['header_row']) {
                continue;
            }
            $cells = $row['cells'];
            if (in_array($row['number'], $sheet['label_rows'], true)) {
                continue;
            }
            $values = array_fill_keys(self::FIELDS, null);
            $errors = [];
            foreach ($mapping as $index => $field) {
                $cell = $cells[$index] ?? null;
                if ($cell instanceof ErrorCell) {
                    $errors[] = $field === 'employee_code' ? 'employee_code_required' : 'invalid_number';

                    continue;
                }
                if ($cell instanceof FormulaCell && isset($missingFormulaValues[$this->cellReference((int) $index, $row['number'])])) {
                    $errors[] = 'formula_without_value';

                    continue;
                }
                $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell?->getValue();
                if ($cell instanceof FormulaCell && ($value === null || ! is_numeric($value))) {
                    $errors[] = 'formula_without_value';

                    continue;
                }
                if ($field === 'employee_code') {
                    if (is_int($value) || is_float($value)) {
                        if (! is_finite((float) $value) || floor((float) $value) !== (float) $value
                            || abs((float) $value) > 9007199254740991) {
                            $errors[] = 'ambiguous_code';
                        } else {
                            $code = sprintf('%.0F', $value);
                            $values[$field] = $code;
                            if ($codeLength !== null && strlen($code) < $codeLength) {
                                $errors[] = 'ambiguous_code';
                            }
                        }
                    } else {
                        $values[$field] = trim((string) ($value ?? ''));
                    }

                    continue;
                }
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }
                if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                    $errors[] = 'invalid_number';

                    continue;
                }
                $number = trim((string) $value);
                if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $number) || ! is_finite((float) $number)
                    || abs((float) $number) >= 100000) {
                    $errors[] = 'invalid_number';

                    continue;
                }
                $formatted = number_format((float) $number, 4, '.', '');
                if (abs((float) $formatted) >= 100000) {
                    $errors[] = 'invalid_number';

                    continue;
                }
                $values[$field] = $formatted;
            }
            if ($values['employee_code'] === null || $values['employee_code'] === '' || mb_strlen($values['employee_code']) > 255) {
                $errors[] = 'employee_code_required';
                $values['employee_code'] = $values['employee_code'] === null ? null : mb_substr($values['employee_code'], 0, 255);
            }
            if ($values['remaining_days'] === null) {
                $errors[] = 'remaining_days_required';
            }

            yield ['number' => $row['number'], 'values' => $values, 'errors' => array_values(array_unique($errors))];
        }
    }

    private function pathAndName(UploadedFile|string $file, ?string $name): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        if (! is_string($path) || ! is_file($path) || filesize($path) === 0 || filesize($path) > 50 * 1024 * 1024
            || mb_strlen($name) > 255 || ! in_array(mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.invalid_file')]);
        }

        return [$path, $name];
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
                        yield ['number' => ++$number, 'cells' => $row->getCells()];
                    }
                    break;
                }
            } catch (\Throwable) {
                throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.invalid_file')]);
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
                            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.invalid_file')]);
                        }
                    }
                }
                unset($cell);
                if ($cells !== [null]) {
                    yield ['number' => $number, 'cells' => array_map(fn (?string $value) => Cell::fromValue($value), $cells)];
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    private function headerKey(string $value): string
    {
        return preg_replace('/\s+/u', '', $this->normalized($value));
    }

    private function suggestedYear(array $headers): ?int
    {
        foreach ($headers as $header) {
            $key = $this->headerKey($header);
            if ((str_contains($key, 'entiteledin') || str_contains($key, 'entitledin'))
                && preg_match('/\b20\d{2}\b/', $header, $match)) {
                return (int) $match[0];
            }
        }
        $years = [];
        foreach ($headers as $header) {
            if (preg_match_all('/\b20\d{2}\b/', $header, $matches)) {
                $years = array_merge($years, array_map('intval', $matches[0]));
            }
        }

        return $years === [] ? null : max($years);
    }

    private function isLabelRow(array $cells): bool
    {
        $labels = false;
        foreach ($cells as $cell) {
            $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
            if (is_int($value) || is_float($value)) {
                return false;
            }
            $text = $this->normalized((string) $value);
            $labels = $labels || in_array($text, ['plan', 'act'], true) || ($text !== '' && $this->month($text) !== null);
        }

        return $labels;
    }

    private function month(string $header): ?int
    {
        $names = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        foreach ($names as $index => $name) {
            if (preg_match('/\b'.($name === 'may' ? 'may' : $name.'[a-z]*').'\b/', $header)) {
                return $index + 1;
            }
        }

        return null;
    }

    private function missingFormulaValues(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.invalid_file')]);
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $relations !== false) {
            $document = new DOMDocument;
            $relationDocument = new DOMDocument;
            if (@$document->loadXML($workbook, LIBXML_NONET) && @$relationDocument->loadXML($relations, LIBXML_NONET)) {
                $sheets = (new DOMXPath($document))->query('//*[local-name()="sheets"]/*[local-name()="sheet"]');
                $id = $sheets?->item(0)?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                foreach ((new DOMXPath($relationDocument))->query('//*[local-name()="Relationship"]') as $relation) {
                    if ($relation->getAttribute('Id') !== $id || ! str_ends_with($relation->getAttribute('Type'), '/worksheet')) {
                        continue;
                    }
                    $target = ltrim($relation->getAttribute('Target'), '/');
                    $candidate = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    if (! str_contains($candidate, '..')) {
                        $sheetPath = $candidate;
                    }
                    break;
                }
            }
        }
        if ($zip->locateName($sheetPath) === false) {
            $zip->close();
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.invalid_file')]);
        }
        $zip->close();
        $uri = 'zip://'.str_replace('\\', '/', $path).'#'.$sheetPath;
        $reader = XMLReader::open($uri, null, LIBXML_NONET);
        if ($reader === false) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.invalid_file')]);
        }
        $missing = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                    continue;
                }
                $reference = $reader->getAttribute('r');
                $xml = $reader->readOuterXml();
                if ($reference !== null && preg_match('/<(?:\w+:)?f\b/', $xml)
                    && ! preg_match('/<(?:\w+:)?v\b[^>]*>[^<]+<\//', $xml)) {
                    $missing[$reference] = true;
                }
            }
        } finally {
            $reader->close();
        }

        return $missing;
    }

    private function cellReference(int $index, int $row): string
    {
        $column = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $column = chr(65 + ($number - 1) % 26).$column;
        }

        return $column.$row;
    }
}
