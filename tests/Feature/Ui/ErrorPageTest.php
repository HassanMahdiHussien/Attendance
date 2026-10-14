<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

test('a forbidden page explains the refusal and offers a way back', function (string $locale, string $title, string $back): void {
    $company = Company::factory()->create();
    $member = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember,
        'locale' => $locale]);

    $this->actingAs($member)->get('/cases')->assertForbidden()
        ->assertSee($title)->assertSee($back)->assertSee($member->email)->assertSee('href="/home"', false);
})->with([
    'English' => ['en', 'You do not have access to this page', 'Go to my work'],
    'Arabic' => ['ar', 'ليست لديك صلاحية لفتح هذه الصفحة', 'الذهاب إلى عملي اليوم'],
]);
