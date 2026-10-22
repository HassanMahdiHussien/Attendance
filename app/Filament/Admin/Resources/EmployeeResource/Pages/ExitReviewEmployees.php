<?php

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Resources\EmployeeResource;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Employees\ExitReview;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ExitReviewEmployees extends Page
{
    protected static string $resource = EmployeeResource::class;

    protected string $view = 'filament.admin.resources.employee-resource.pages.exit-review-employees';

    public array $lastWorkingDays = [];

    public array $dismissReasons = [];

    public function getTitle(): string
    {
        return __('Exit review');
    }

    public static function canAccess(array $parameters = []): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && Gate::allows('importEmployees', $tenant);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function pendingEmployees(): Collection
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company, 403);

        return app(ExitReview::class)->pending($company)->with(['user', 'exitReviewBatch'])->orderBy('name')->get();
    }

    public function confirm(int $employeeId): void
    {
        $employee = $this->employee($employeeId);
        $this->validate(['lastWorkingDays.'.$employeeId => 'required|date']);
        app(ExitReview::class)->confirm($employee, $this->lastWorkingDays[$employeeId], auth()->user());
        unset($this->lastWorkingDays[$employeeId]);
    }

    public function dismiss(int $employeeId): void
    {
        $employee = $this->employee($employeeId);
        $this->validate(['dismissReasons.'.$employeeId => 'required|string|max:255']);
        app(ExitReview::class)->dismiss($employee, auth()->user(), $this->dismissReasons[$employeeId]);
        unset($this->dismissReasons[$employeeId]);
    }

    private function employee(int $employeeId): Employee
    {
        abort_unless(static::canAccess(), 403);
        $company = Filament::getTenant();

        return Employee::query()->where('company_id', $company->id)->findOrFail($employeeId);
    }
}
