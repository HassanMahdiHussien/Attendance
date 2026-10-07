<?php

namespace App\LegalLibrary;

interface PageReader
{
    public function read(string $pngPath, array $context): PageReading;
}
