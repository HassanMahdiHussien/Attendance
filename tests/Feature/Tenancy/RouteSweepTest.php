<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// CLAUDE.md §16.1: a Company A user cannot fetch Company B's data through any route, including routes the UI does not link to.
// Every parameterised GET route must be listed here so a new route fails until its tenant boundary is swept.
const SWEEP_EXCLUDED = [
    // Livewire asset parameters select framework CSS or JavaScript, not tenant records.
    '#^livewire-[^/]+/(css|js)/#',
    // Livewire upload previews use temporary upload tokens, not application records.
    '#^livewire-[^/]+/preview-file/#',
    // Filament's built-in exporter and importer routes have no application records; their tables are checked below.
    '#^filament/(exports|imports)/#',
];

test('framework exporter and importer tables remain absent', function (): void {
    expect(Schema::hasTable('exports'))->toBeFalse()
        ->and(Schema::hasTable('imports'))->toBeFalse();
});

test('a company user cannot reach another company through any parameterised GET route', function (): void {
    $context = app(TenantContext::class);
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::HrStaff]);
    $marker = 'private-company-b-'.uniqid();

    $records = $context->runForCompany($b, function () use ($b, $marker): array {
        $branch = Branch::factory()->create(['company_id' => $b->id, 'name' => $marker]);
        $employee = Employee::factory()->create(['company_id' => $b->id, 'branch_id' => $branch->id, 'name' => $marker]);
        $user = User::factory()->create(['company_id' => $b->id, 'primary_role' => UserRole::HrStaff, 'name' => $marker]);
        $location = Location::factory()->create(['company_id' => $b->id, 'branch_id' => $branch->id]);
        Storage::disk('local')->put('sweep/'.$marker.'.txt', $marker);

        return [
            'company' => $b->id, 'tenant' => $b->id, 'employee' => $employee->id,
            'path' => 'sweep/'.$marker.'.txt',
            // Help paths name shared static content, never a tenant record.
            'section' => 'missing', 'slug' => 'missing', 'locale' => 'xx', 'name' => 'missing',
            'record' => [
                'branches' => $branch->id, 'employees' => $employee->id,
                'users' => $user->id, 'locations' => $location->id,
                'companies' => $b->id, 'platform-users' => $user->id,
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
                $leaks[] = $route->uri().' -> '.$response->getStatusCode();
            }
        });

    // A filter change must not silently remove every tenant route from the sweep.
    expect($visited)->toBeGreaterThan(10)
        ->and(array_values(array_unique($unmapped)))->toBe([])
        ->and($leaks)->toBe([]);
});
