<?php

namespace App\LegalLibrary;

interface BatchClient
{
    public function create(array $requests): string;

    public function status(string $batchId): string;

    public function results(string $batchId): iterable;

    public function cancel(string $batchId): void;
}
