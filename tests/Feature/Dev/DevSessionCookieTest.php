<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\LocalUsersSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('refuses sessions outside the local environment', function (): void {
    expect(Artisan::call('dev:session-cookie', ['email' => 'hr@example.test']))->toBe(1);
    expect(Artisan::output())->toContain('only in the local environment');
});

it('refuses non-demo addresses and missing demo users', function (): void {
    $previous = app()['env'];
    app()['env'] = 'local';
    try {
        expect(Artisan::call('dev:session-cookie', ['email' => 'person@example.com']))->toBe(1);
        expect(Artisan::output())->toContain('@example.test');
        expect(Artisan::call('dev:session-cookie', ['email' => 'missing@example.test']))->toBe(1);
        expect(Artisan::output())->toContain('Demo user not found');
    } finally {
        app()['env'] = $previous;
    }
});

it('issues a demo session with names and restores the original locale', function (): void {
    $company = Company::factory()->create(['name' => LocalUsersSeeder::DEMO_COMPANY_NAME]);
    $user = User::factory()->create([
        'name' => 'Demo Auditor',
        'email' => 'hr@example.test',
        'company_id' => $company->id,
        'primary_role' => UserRole::HrStaff,
        'locale' => 'ar',
    ]);
    $previous = app()['env'];
    $sessionDriver = config('session.driver');
    app()['env'] = 'local';
    config(['session.driver' => 'database']);
    try {
        expect(Artisan::call('dev:session-cookie', [
            'email' => $user->email, '--locale' => 'en',
        ]))->toBe(0);
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        expect($result['name'])->toBe(config('session.cookie'))
            ->and($result['value'])->toBeString()->not->toBeEmpty()
            ->and($result['originalLocale'])->toBe('ar')
            ->and($result['names'])->toContain('Demo Auditor', LocalUsersSeeder::DEMO_COMPANY_NAME)
            ->and($user->fresh()->locale)->toBe('en')
            ->and(DB::table('sessions')->count())->toBeGreaterThan(0);

        expect(Artisan::call('dev:session-cookie', [
            'email' => $user->email, '--restore-locale' => $result['originalLocale'],
        ]))->toBe(0);
        expect($user->fresh()->locale)->toBe('ar');
    } finally {
        app()['env'] = $previous;
        config(['session.driver' => $sessionDriver]);
        app('session')->forgetDrivers();
    }
});
