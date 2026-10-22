<?php

use App\Exceptions\MissingTenantContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

test('an unset context fails closed and nested company contexts restore on exception', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $context->clear();

    expect(fn () => Branch::count())->toThrow(MissingTenantContext::class);
    $context->setCompany($a);
    expect(fn () => $context->runForCompany($b, function () use ($context, $b): void {
        expect($context->companyId())->toBe($b->id);
        throw new RuntimeException('stop');
    }))->toThrow(RuntimeException::class);
    expect($context->companyId())->toBe($a->id);

    expect($context->runAcrossAllCompanies(function () use ($context): bool {
        return $context->isAllCompanies();
    }))->toBeTrue();
    expect($context->companyId())->toBe($a->id);
    expect(fn () => $context->runAcrossAllCompanies(function (): void {
        throw new RuntimeException('stop');
    }))->toThrow(RuntimeException::class);
    expect($context->companyId())->toBe($a->id);
    $context->setAllCompanies();
    expect(fn () => Branch::factory()->create(['company_id' => null]))->toThrow(MissingTenantContext::class);
    $context->clear();
});

test('the platform admin web context sees all companies', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $context->runForCompany($a, fn () => Branch::factory()->create());
    $context->runForCompany($b, fn () => Branch::factory()->create());
    $companyUser = User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => $a->id]);
    $admin = User::factory()->create(['primary_role' => 'platform_admin']);

    Route::middleware('web')->get('/_tenant-context-test', fn () => response((string) Branch::count()));
    $this->actingAs($companyUser)->get('/_tenant-context-test')->assertOk()->assertSee('1');
    $this->actingAs($admin)->get('/_tenant-context-test')->assertOk()->assertSee('2');
});

test('worker queue events clear context before and after each job', function (): void {
    $company = Company::factory()->create();
    $context = app(TenantContext::class);
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldIgnoreMissing();

    foreach ([
        new JobProcessing('database', $job),
        new JobProcessed('database', $job),
        new JobFailed('database', $job, new RuntimeException('x')),
    ] as $event) {
        $context->setCompany($company);
        event($event);
        expect($context->isSet())->toBeFalse();
    }
});

test('sync jobs keep the caller tenant context', function (): void {
    $company = Company::factory()->create();
    $context = app(TenantContext::class);
    $context->setCompany($company);
    ContextProbeJob::$observations = [];

    Queue::connection('sync')->push(new ContextProbeJob($company->id));

    expect($context->companyId())->toBe($company->id);
});

class ContextProbeJob implements ShouldQueue
{
    public static array $observations = [];

    public function __construct(private readonly ?int $companyId) {}

    public function handle(): void
    {
        self::$observations[] = app(TenantContext::class)->companyId();
        if ($this->companyId !== null) {
            app(TenantContext::class)->setCompany($this->companyId);
        }
    }
}
