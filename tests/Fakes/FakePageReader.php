<?php

namespace Tests\Fakes;

use App\LegalLibrary\AiUnavailable;
use App\LegalLibrary\PageReader;
use App\LegalLibrary\PageReading;

class FakePageReader implements PageReader
{
    public array $pages = [];

    public function read(string $pngPath, array $context): PageReading
    {
        $reading = $this->pages[$context['page']] ?? new AiUnavailable('AI reading unavailable — retry later.');
        if ($reading instanceof \Throwable) {
            throw $reading;
        }

        return $reading instanceof PageReading ? $reading : new PageReading($reading, false);
    }
}
