<?php

namespace App\Services\Cases;

class PresenceWording
{
    public static function suggestion(?string $result): string
    {
        return match ($result) {
            'appears_filled' => 'خانة التوقيع تبدو ممتلئة',
            'appears_empty' => 'خانة التوقيع تبدو فارغة',
            default => 'خانة التوقيع غير واضحة',
        };
    }

    public static function choice(string $choice): string
    {
        return self::suggestion($choice === 'filled' ? 'appears_filled' : 'appears_empty');
    }
}
