<?php

namespace App\Services\Employees;

use App\Services\Branches\BranchMatcher;

class ImportPreview
{
    public function __construct(
        public array $rows,
        public array $notices,
        public string $hash,
        public array $missing = [],
        public ?array $alreadyImported = null,
        public ?string $token = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['rows'], $data['notices'], $data['hash'], $data['missing'] ?? [],
            $data['alreadyImported'] ?? null, $data['token'] ?? null);
    }

    public function toArray(): array
    {
        return ['rows' => $this->rows, 'notices' => $this->notices, 'hash' => $this->hash, 'missing' => $this->missing,
            'alreadyImported' => $this->alreadyImported, 'token' => $this->token];
    }

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
        return count($this->rows) - $this->added() - $this->errors() - $this->held();
    }

    public function held(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['status'] === 'held'));
    }

    public function unmatchedBranches(): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            if ($row['status'] !== 'held') {
                continue;
            }
            $key = BranchMatcher::normalise($row['branch']);
            $values[$key] ??= ['name' => $row['branch'], 'count' => 0];
            $values[$key]['count']++;
        }

        return array_values($values);
    }

    public function unchanged(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['status'] === 'unchanged'));
    }

    public function changed(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => in_array($row['status'], ['update', 'update-inactive'], true)));
    }
}
