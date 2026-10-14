<?php

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('the case viewer opens an issued PDF, moves between attachments and closes on Escape', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $owner = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $case = DisciplinaryCase::factory()->create([
        'company_id' => $company->id,
        'law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open,
        'opened_by_user_id' => $owner->id,
    ]);
    $pdf = '%PDF-1.4 test';
    $pdfPath = "documents/{$company->id}/{$case->id}/sample.pdf";
    Storage::disk('local')->put($pdfPath, $pdf);
    Document::factory()->create([
        'company_id' => $company->id,
        'case_id' => $case->id,
        'status' => 'issued',
        'is_locked' => true,
        'pdf_status' => 'ready',
        'pdf_file_path' => $pdfPath,
        'pdf_sha256' => hash('sha256', $pdf),
    ]);
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
    Storage::disk('local')->put('attachments/photo.png', $image);
    Storage::disk('local')->put('attachments/scan.pdf', $pdf);
    Attachment::factory()->create([
        'company_id' => $company->id, 'attachable_id' => $case->id,
        'path' => 'attachments/photo.png', 'original_name' => 'photo.png', 'mime_type' => 'image/png',
        'size_bytes' => strlen($image),
    ]);
    Attachment::factory()->create([
        'company_id' => $company->id, 'attachable_id' => $case->id,
        'path' => 'attachments/scan.pdf', 'original_name' => 'scan.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdf),
    ]);

    $page = visitBound('/login')->assertNoJavaScriptErrors()
        ->fill('email', $owner->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $page->navigate('/cases/'.$case->id)->assertPathIs('/cases/'.$case->id)->assertNoJavaScriptErrors()
        ->click('#next-documents button[data-file-list]')
        ->assertVisible('[aria-label="File viewer"]')
        ->assertVisible('[aria-label="File viewer"] iframe')
        ->click('[aria-label="File viewer"] button:has-text("Close")')
        ->assertMissing('[aria-label="File viewer"]')
        ->click('button:has(img[alt="photo.png"])')
        ->assertSeeIn('[aria-label="File viewer"]', 'photo.png')
        ->click('[aria-label="File viewer"] button:has-text("Next")')
        ->assertSeeIn('[aria-label="File viewer"]', 'scan.pdf')
        ->click('[aria-label="File viewer"] button:has-text("Previous")')
        ->assertSeeIn('[aria-label="File viewer"]', 'photo.png')
        ->keys('[aria-label="File viewer"] button:has-text("Previous")', 'Escape')
        ->assertMissing('[aria-label="File viewer"]')
        ->assertNoJavaScriptErrors();
});
