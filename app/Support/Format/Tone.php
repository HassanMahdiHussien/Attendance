<?php

namespace App\Support\Format;

class Tone
{
    public static function approval(string $status): string
    {
        return match ($status) {
            'approved' => 'green', 'rejected' => 'red', 'pending' => 'amber', default => 'gray',
        };
    }
}
