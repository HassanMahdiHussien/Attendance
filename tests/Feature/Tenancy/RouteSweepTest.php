<?php

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\HrPartner;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\Location;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\ViolationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// CLAUDE.md §16.1: a Company A user can never fetch Company B's data through any route, not just the ones the UI links to.
// Every parameterised GET route must be listed here, so a new route fails this test until someone decides how to sweep it.
const SWEEP_EXCLUDED = [
    // Framework asset and upload-preview routes: no tenant data behind the parameter.
    '#^livewire-[^/]+/(css|js)/#',
    '#^livewire-[^/]+/preview-file/#',
    // Filament's built-in exporter/importer downloads: the app uses neither, so no exports/imports tables exist (guarded below).
    '#^filament/(exports|imports)/#',
];

test('Filament exporter and importer tables stay absent while their download routes are excluded from the sweep', function (): void {
    expect(Schema::hasTable('exports'))->toBeFalse()
        ->and(Schema::hasTable('imports'))->toBeFalse();
});

test('a company admin cannot reach another company\'s records through any parameterised GET route', function (): void {
    $context = app(TenantContext::class);
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::CompanyAdmin]);
    $marker = 'سري-للشركة-ب-'.uniqid();

    $context->setAllCompanies();
    $statute = Law::factory()->create();
    $records = $context->runForCompany($b, function () use ($b, $statute, $marker): array {
        $branch = Branch::factory()->create(['company_id' => $b->id, 'name' => $marker]);
        $employee = Employee::factory()->create(['company_id' => $b->id, 'branch_id' => $branch->id, 'name' => $marker]);
        $case = DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
            'law_version_id' => $statute->id]);
        $document = Document::factory()->create(['case_id' => $case->id]);
        Storage::disk('local')->put('sweep/'.$marker.'.txt', $marker);
        $attachment = Attachment::factory()->create(['attachable_type' => 'case', 'attachable_id' => $case->id, 'path' => 'sweep/'.$marker.'.txt']);
        $regulation = Law::factory()->create(['company_id' => $b->id, 'kind' => 'internal_regulation',
            'category' => 'regulation', 'name' => $marker]);
        $userB = User::factory()->create(['company_id' => $b->id, 'primary_role' => UserRole::HrStaff, 'name' => $marker]);
        $run = LegalImportRun::create(['law_id' => $regulation->id, 'company_id' => $b->id, 'source_path' => 'sweep/source.pdf',
            'source_sha256' => str_repeat('c', 64), 'mode' => 'auto', 'state' => 'completed', 'started_by' => $userB->id,
            'started_at' => now()]);
        Storage::disk('local')->put('sweep/page.png', $marker);
        LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1, 'image_path' => 'sweep/page.png',
            'image_sha256' => hash('sha256', $marker)]);

        return [
            'company' => $b->id, 'tenant' => $b->id, 'case' => $case->id, 'document' => $document->id,
            'attachment' => $attachment->id, 'employee' => $employee->id, 'run' => $run->id, 'page' => 1,
            'export' => ReportExport::factory()->create(['company_id' => $b->id, 'requested_by' => $userB->id])->id,
            'path' => 'sweep/'.$marker.'.txt',
            // Help paths name shared static content, never a tenant record.
            'section' => 'missing', 'slug' => 'missing', 'locale' => 'xx', 'name' => 'missing',
            'record' => [
                'branches' => $branch->id, 'employees' => $employee->id, 'users' => $userB->id,
                'locations' => Location::factory()->create(['company_id' => $b->id, 'branch_id' => $branch->id])->id,
                'violation-types' => ViolationType::factory()->create(['company_id' => $b->id])->id,
                'committee-mandates' => CommitteeMandate::factory()->create(['company_id' => $b->id])->id,
                'companies' => $b->id, 'laws' => $regulation->id, 'platform-users' => $userB->id,
                'hr-partners' => HrPartner::factory()->create()->id,
                'law-firm-partners' => LawFirmPartner::factory()->create()->id,
            ],
        ];
    });
    $context->clear();

    $unmapped = [];
    $leaks = [];
    $visited = 0;
    $this->actingAs($actor);
    collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true) && str_contains($route->uri(), '{'))
        ->reject(fn (RoutingRoute $route) => collect(SWEEP_EXCLUDED)->contains(fn (string $pattern) => preg_match($pattern, $route->uri())))
        ->each(function (RoutingRoute $route) use ($records, $marker, &$unmapped, &$leaks, &$visited): void {
            $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($records, $route, &$unmapped): string {
                $name = $match[1];
                if ($name === 'record') {
                    $resource = collect(explode('/', $route->uri()))->first(fn (string $segment) => isset($records['record'][$segment]));
                    if ($resource === null) {
                        $unmapped[] = $route->uri();

                        return '0';
                    }

                    return (string) $records['record'][$resource];
                }
                if (! array_key_exists($name, $records)) {
                    $unmapped[] = $route->uri();

                    return '0';
                }

                return (string) $records[$name];
            }, $route->uri());

            $response = $this->get('/'.$uri);
            $visited++;
            $body = (string) $response->getContent();
            if ($response->getStatusCode() === 200 || str_contains($body, $marker)) {
                $leaks[] = $route->uri().' → '.$response->getStatusCode();
            }
        });

    // Guards against a filter change that silently sweeps nothing.
    expect($visited)->toBeGreaterThan(40)
        ->and(array_values(array_unique($unmapped)))->toBe([])
        ->and($leaks)->toBe([]);
});
