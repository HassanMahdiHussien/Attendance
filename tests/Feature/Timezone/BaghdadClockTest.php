<?php

use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\User;
use App\Services\Documents\DocumentIssuer;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;

test('the application clock follows Baghdad at the year boundary', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Baghdad');
    Carbon::setTestNow(Carbon::parse('2026-12-31 21:30:00', 'UTC'));
    expect(now()->year)->toBe(2027);
    Carbon::setTestNow();
});

test('a document issued after Baghdad midnight uses the new reference year', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-12-31 21:30:00', 'UTC'));
    try {
        $company = Company::factory()->create();
        $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
        app(TenantContext::class)->setCompany($company);
        $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $actor->id]);
        $document = Document::factory()->create(['case_id' => $case->id]);
        foreach (['ar', 'en'] as $language) {
            DocumentContent::factory()->create(['document_id' => $document->id, 'language_code' => $language]);
        }
        expect(app(DocumentIssuer::class)->issue($document, $actor)->reference_year)->toBe(2027);
    } finally {
        app(TenantContext::class)->clear();
        Carbon::setTestNow();
    }
});
