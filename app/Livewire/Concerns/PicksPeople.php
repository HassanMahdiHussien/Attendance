<?php

namespace App\Livewire\Concerns;

use App\Services\Employees\EmployeePicker;
use Illuminate\Validation\ValidationException;

trait PicksPeople
{
    public array $peopleSearch = ['employees' => '', 'witnesses' => '', 'responsible' => ''];

    public array $people = ['employees' => [], 'witnesses' => [], 'responsible' => []];

    public function updatedBranchId(): void
    {
        $this->people = ['employees' => [], 'witnesses' => [], 'responsible' => []];
        $this->peopleSearch = ['employees' => '', 'witnesses' => '', 'responsible' => ''];
    }

    public function addPerson(string $kind, int $id, EmployeePicker $picker): void
    {
        $this->authorizePeopleKind($kind);
        $employee = $picker->employee(auth()->user(), (int) $this->branchId, $id);
        if ($kind === 'responsible' || ($kind === 'employees' && ! $this->allowsMultipleEmployees())) {
            $this->people[$kind] = [];
        }
        if (! collect($this->people[$kind])->contains(fn ($item) => (int) ($item['employee_id'] ?? 0) === $id)) {
            $this->people[$kind][] = ['employee_id' => $id, 'name' => $employee->name];
        }
        $this->peopleSearch[$kind] = '';
    }

    public function addFreePerson(string $kind): void
    {
        $this->authorizePeopleKind($kind);
        abort_unless($kind !== 'employees' || $this->allowsFreeEmployees(), 403);
        $name = trim($this->peopleSearch[$kind] ?? '');
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(["peopleSearch.$kind" => __('Choose a person or enter a name')]);
        }
        if ($kind === 'responsible') {
            $this->people[$kind] = [];
        }
        if (! collect($this->people[$kind])->contains(fn ($item) => mb_strtolower($item['name'] ?? '') === mb_strtolower($name))) {
            $this->people[$kind][] = ['employee_id' => null, 'name' => $name];
        }
        $this->peopleSearch[$kind] = '';
    }

    public function removePerson(string $kind, int $index): void
    {
        $this->authorizePeopleKind($kind);
        array_splice($this->people[$kind], $index, 1);
    }

    protected function pickedPeople(string $kind, bool $allowFree = true): array
    {
        return app(EmployeePicker::class)->selections(auth()->user(), (int) $this->branchId, $this->people[$kind] ?? [], $allowFree);
    }

    protected function peopleResults(EmployeePicker $picker): array
    {
        if ($this->branchId === '') {
            return ['employees' => [], 'witnesses' => [], 'responsible' => []];
        }

        return collect(['employees', 'witnesses', 'responsible'])->mapWithKeys(fn ($kind) => [
            $kind => $picker->search(auth()->user(), (int) $this->branchId, $this->peopleSearch[$kind] ?? ''),
        ])->all();
    }

    private function authorizePeopleKind(string $kind): void
    {
        abort_unless(in_array($kind, ['employees', 'witnesses', 'responsible'], true)
            && ($kind !== 'responsible' || ! $this->allowsFreeEmployees()), 403);
    }

    protected function allowsFreeEmployees(): bool
    {
        return false;
    }

    protected function allowsMultipleEmployees(): bool
    {
        return true;
    }
}
