<?php

namespace App\LegalLibrary;

class WordLigatureRepair
{
    public function repair(string $text): string
    {
        return str_replace(
            ['املادة', 'ألحكام'],
            ['المادة', 'لأحكام'],
            $text,
        );
    }
}
