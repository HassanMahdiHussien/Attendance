<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('a forbidden page explains the refusal and offers a way back', function (string $locale, string $title, string $back): void {
    // With only platform admins and HR left, no workspace screen refuses an HR user, so a probe route raises
    // the 403 through the normal web stack; another company's record answers 404 and must not be used here.
    Route::middleware(['web', 'auth'])->get('/forbidden-probe', fn () => abort(403));
    $company = Company::factory()->create();
    $member = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff,
        'locale' => $locale]);

    $this->actingAs($member)->get('/forbidden-probe')->assertForbidden()
        ->assertSee($title)->assertSee($back)->assertSee($member->email)->assertSee('href="/home"', false);
})->with([
    'English' => ['en', 'You do not have access to this page', 'Go to my work'],
    'Arabic' => ['ar', 'ليست لديك صلاحية لفتح هذه الصفحة', 'الذهاب إلى عملي اليوم'],
]);
