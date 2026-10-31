<?php

namespace App\Services\Employees;

use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

class SpreadsheetReader
{
    public function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || filesize($path) === 0 || filesize($path) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.file_empty_or_too_large')]);
        }
        if (mb_strlen(basename(str_replace('\\', '/', $file->getClientOriginalName()))) > 255) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.file_name_too_long')]);
        }
        $extension = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.unsupported_file')]);
        }

        $records = $extension === 'xlsx' ? $this->xlsx($path) : $this->csv($path);
        if ($records === []) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.no_employees')]);
        }
        if (count($records) > 5001) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.row_limit')]);
        }
        $headers = array_map(fn (mixed $value): string => trim((string) $value), array_shift($records));
        if ($extension !== 'xlsx') {
            CsvFormat::assertReadableHeader($headers);
        }
        $rows = [];
        foreach ($records as $number => $cells) {
            $rows[] = ['number' => $number + 2, 'cells' => $cells, 'columns_valid' => count($cells) === count($headers)];
        }

        return ['headers' => $headers, 'rows' => $rows, 'hash' => hash_file('sha256', $path)];
    }

    /** Header and label text: a date or time cell is data, never a label, and cannot be cast to a string. */
    public static function label(mixed $cell): string
    {
        return $cell instanceof DateTimeInterface || $cell instanceof DateInterval ? '' : trim((string) $cell);
    }

    public function value(mixed $cell, string $field): string
    {
        if ($cell === null || $cell === false || $cell === true) {
            return '';
        }
        if ($cell instanceof DateTimeInterface) {
            return match ($field) {
                'hire_date' => $cell->format('Y-m-d'),
                'schedule_start', 'schedule_end' => $cell->format('H:i:s'),
                default => '',
            };
        }
        if ($cell instanceof DateInterval) {
            return in_array($field, ['schedule_start', 'schedule_end'], true) ? $cell->format('%H:%I:%S') : '';
        }
        if (is_float($cell) || is_int($cell)) {
            if (in_array($field, ['employee_code', 'manager_code'], true)) {
                if (! is_finite((float) $cell) || floor((float) $cell) !== (float) $cell
                    || (is_float($cell) && abs($cell) > 9007199254740991)) {
                    throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.ambiguous_id')]);
                }

                return sprintf('%.0F', $cell);
            }
            if ($field === 'hire_date') {
                if ($cell < 1 || floor((float) $cell) !== (float) $cell) {
                    return (string) $cell;
                }

                return CarbonImmutable::parse('1899-12-30')->addDays((int) $cell)->toDateString();
            }

            return (string) $cell;
        }
        $value = trim((string) $cell);

        return preg_match('/^#(?:N\/A|REF!|VALUE!|DIV\/0!|NUM!|NAME\?|NULL!)$/i', $value) ? '' : $value;
    }

    private function xlsx(string $path): array
    {
        $reader = new Reader;
        $records = [];
        $opened = false;
        try {
            $reader->open($path);
            $opened = true;
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $records[] = $row->toArray();
                    if (count($records) > 5001) {
                        break;
                    }
                }
                break;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.unreadable_file')]);
        } finally {
            if ($opened) {
                $reader->close();
            }
        }

        return $records;
    }

    private function csv(string $path): array
    {
        $text = file_get_contents($path);
        $text = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = iconv('CP1256', 'UTF-8//IGNORE', $text);
        }
        if ($text === false || ! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.encoding_unreadable')]);
        }
        $first = strtok($text, "\r\n") ?: '';
        $delimiter = CsvFormat::delimiter($first);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $text);
        rewind($stream);
        $records = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $records[] = $row;
            }
            if (count($records) > 5001) {
                break;
            }
        }
        fclose($stream);

        return $records;
    }
}
