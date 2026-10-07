<?php

namespace App\LegalLibrary;

readonly class PageReading
{
    public function __construct(public string $text, public bool $illegible) {}
}
