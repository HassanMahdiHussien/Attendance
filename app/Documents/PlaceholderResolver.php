<?php

namespace App\Documents;

use App\Enums\EmployeeGender;
use App\Exceptions\IssuanceBlocked;

class PlaceholderResolver
{
    public function resolve(string $block, array $facts, EmployeeGender $gender): string
    {
        $block = preg_replace_callback('/\{m:([^{}|]+)\|f:([^{}|]+)\}/u',
            fn ($match) => $gender === EmployeeGender::Female ? $match[2] : $match[1], $block);
        $result = preg_replace_callback('/\{([^{}]+)\}/u', function ($match) use ($facts): string {
            if (! array_key_exists($match[1], $facts) || $facts[$match[1]] === null) {
                throw new IssuanceBlocked("Missing document fact {$match[1]}.");
            }

            return (string) $facts[$match[1]];
        }, $block);
        if (str_contains($result, '{') || str_contains($result, '}')) {
            throw new IssuanceBlocked('Unresolved template placeholder.');
        }

        return $result;
    }
}
