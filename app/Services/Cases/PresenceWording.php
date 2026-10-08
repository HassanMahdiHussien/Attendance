<?php

namespace App\Services\Cases;

class PresenceWording
{
    public static function suggestion(?string $result): string
    {
        return match ($result) {
            'appears_filled' => __('messages.signature.box_appears_filled'),
            'appears_empty' => __('messages.signature.box_appears_empty'),
            default => __('messages.signature.box_unclear'),
        };
    }

    public static function choice(string $choice): string
    {
        return self::suggestion($choice === 'filled' ? 'appears_filled' : 'appears_empty');
    }
}
