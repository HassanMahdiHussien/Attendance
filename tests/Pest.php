<?php

use App\Enums\Jurisdiction;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\Transaction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        app()->setLocale('en');
        Storage::fake('local');
    })
    ->in('Feature', 'Browser');

function actingInAdminTenant(TestCase $test, User $user, Company $company): void
{
    $test->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($company);
    app(TenantContext::class)->setCompany($company);
}

function completeCompanySetup(Company $company): array
{
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::query()->where('company_id', $company->id)->first()
        ?? Branch::factory()->create(['company_id' => $company->id, 'jurisdiction' => Jurisdiction::FederalIraq]);

    return ['branch' => $branch];
}

function attendanceAbsenceEvidence(Company $company, Branch $branch, Employee $employee, ImportBatch $batch, string $lastDate = '2026-10-21'): void
{
    app(TenantContext::class)->runForCompany($company, function () use ($company, $branch, $employee, $batch, $lastDate): void {
        $device = Device::factory()->create(['company_id' => $company->id, 'status' => 'approved']);
        DeviceSetting::factory()->create(['company_id' => $company->id, 'device_id' => $device->id,
            'branch_id' => $branch->id, 'role' => 'check_out', 'effective_from' => '2026-10-18']);
        foreach ([['2026-10-18', $employee->employee_code], [$lastDate, 'FAKE-OTHER']] as [$date, $code]) {
            Transaction::factory()->create(['company_id' => $company->id, 'first_batch_id' => $batch->id,
                'device_id' => $device->id, 'serial_number' => $device->serial_number,
                'person_code' => $code, 'punch_date' => $date, 'punch_time' => '17:00',
                'punched_at' => $date.' 17:00:00', 'punch_state' => 'Check Out']);
        }
    });
}

/**
 * Browser page whose fill() waits until Livewire has bound the input (Alpine's _x_model), then types and re-types
 * once if Livewire's boot wiped the value. Typing straight after a wire:navigate swap or a fresh load raced that boot.
 */
final class BoundPage
{
    public function __construct(private object $page) {}

    public function fill(string $field, string $value): self
    {
        $selector = json_encode($field);
        $find = "document.getElementById({$selector}) || document.querySelector('[name=' + JSON.stringify({$selector}) + ']')";
        $deadline = microtime(true) + 10;
        while (! $this->page->script("() => { const el = {$find}; return !! (el && el._x_model); }")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Input [{$field}] was never bound by Livewire.");
            }
            usleep(100_000);
        }
        $this->page->fill($field, $value);
        if ($this->page->script("() => ({$find}).value") !== $value) {
            $this->page->fill($field, $value);
        }

        return $this;
    }

    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->page->{$method}(...$arguments);

        return $result instanceof Webpage || $result instanceof PendingAwaitablePage
            ? new self($result) : $result;
    }
}

function visitBound(string $url): BoundPage
{
    return new BoundPage(visit($url));
}

// Browser hydration can outlast Playwright's short default timeout on a first page load.
pest()->browser()->timeout(20_000);
