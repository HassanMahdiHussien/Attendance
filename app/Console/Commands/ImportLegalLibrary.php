<?php

namespace App\Console\Commands;

use App\LegalLibrary\LegalLibraryArchive;
use Illuminate\Console\Command;
use Throwable;

class ImportLegalLibrary extends Command
{
    protected $signature = 'legal:import';

    protected $description = 'Import verified platform laws from the checked JSON manifest';

    public function handle(LegalLibraryArchive $archive): int
    {
        try {
            $count = $archive->import();
            $this->info("Imported {$count} platform law file(s).");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
