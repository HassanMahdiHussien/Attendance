<?php

namespace App\Services\Employees;

use Illuminate\Validation\ValidationException;

class CsvFormat
{
    public static function delimiter(string $header): string
    {
        $counts = [];
        foreach ([',', ';', "\t"] as $delimiter) {
            $counts[$delimiter] = count(str_getcsv($header, $delimiter, '"', ''));
        }

        return array_search(max($counts), $counts, true);
    }

    public static function delimiterFromLines(array $lines): string
    {
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        foreach ($lines as $line) {
            foreach (array_keys($counts) as $delimiter) {
                $counts[$delimiter] = max($counts[$delimiter], count(str_getcsv($line, $delimiter, '"', '')));
            }
        }

        return array_search(max($counts), $counts, true);
    }

    public static function assertReadableHeader(array $headers): void
    {
        $text = preg_replace('/[\s,;\t]+/u', '', implode('', $headers));
        $length = mb_strlen($text);
        if ($length > 0 && mb_substr_count($text, '?') * 2 >= $length) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.import.damaged_arabic_header')]);
        }
    }
}
