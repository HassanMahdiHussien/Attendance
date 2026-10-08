<?php

namespace App\Services\Employees;

class ImportPreview
{
    public function __construct(
        public array $rows,
        public array $notices,
        public string $hash,
    ) {}

    public function errors(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['status'] === 'error'));
    }

    public function added(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['status'] === 'new'));
    }

    public function updated(): int
    {
        return count($this->rows) - $this->added() - $this->errors();
    }
}
