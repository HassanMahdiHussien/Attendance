<?php

namespace App\Console\Commands;

use App\LegalLibrary\LegalLibraryArchive;
use Illuminate\Console\Command;
use Throwable;

class ExportLegalLibrary extends Command
{
    protected $signature = 'legal:export {--law=* : Law numbers to export}';

    protected $description = 'Export verified platform laws to versioned JSON';

    public function handle(LegalLibraryArchive $archive): int
    {
        try {
            $numbers = $this->option('law');
            $count = $archive->export($numbers === [] ? null : $numbers);
            $this->info("Exported {$count} platform law file(s).");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
