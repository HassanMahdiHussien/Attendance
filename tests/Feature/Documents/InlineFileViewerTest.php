<?php

use App\Enums\CaseStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Support\Files\FileItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

test('inline PDF uses the same access and integrity checks as download', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $owner = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $owner->id]);
    $path = "documents/{$company->id}/{$case->id}/sample.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 test');
    $document = Document::factory()->create(['case_id' => $case->id, 'status' => 'issued',
        'is_locked' => true, 'pdf_status' => 'ready', 'pdf_file_path' => $path,
        'pdf_sha256' => hash('sha256', '%PDF-1.4 test')]);

    $this->actingAs($owner)->get(route('documents.view', $document))->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="sample.pdf"')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('x-frame-options', 'SAMEORIGIN')
        ->assertHeader('cache-control', 'no-store, private')
        ->assertHeader('content-security-policy', "default-src 'none'; frame-ancestors 'self'");
    $this->get(route('documents.pdf', $document))->assertOk();

    $other = Company::factory()->create();
    $foreign = User::factory()->create(['company_id' => $other->id, 'primary_role' => 'company_admin']);
    $this->actingAs($foreign)->get(route('documents.view', $document))->assertNotFound();
    $this->get(route('documents.pdf', $document))->assertNotFound();
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $this->actingAs($reporter)->get(route('documents.view', $document))->assertForbidden();
    $this->get(route('documents.pdf', $document))->assertForbidden();
    $limitedHr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $this->actingAs($limitedHr)->get(route('documents.view', $document))->assertForbidden();
    $this->get(route('documents.pdf', $document))->assertForbidden();

    Storage::disk('local')->put($path, '%PDF-1.4 tampered');
    $this->actingAs($owner)->get(route('documents.view', $document))->assertStatus(409);
    $this->get(route('documents.pdf', $document))->assertStatus(409);
});

test('attachment previews keep their MIME allowlist and same-origin framing', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $owner = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $owner->id]);
    Storage::disk('local')->put('attachments/photo.jpg', 'image');
    $photo = Attachment::factory()->create(['attachable_id' => $case->id,
        'path' => 'attachments/photo.jpg', 'original_name' => 'photo.jpg', 'mime_type' => 'image/jpeg']);

    $this->actingAs($owner)->get(route('attachments.download', $photo).'?preview=1')->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('x-frame-options', 'SAMEORIGIN')
        ->assertHeader('cache-control', 'no-store, private');
    expect(FileItem::forAttachment($photo)['kind'])->toBe('image');
    $photo->mime_type = 'application/pdf';
    expect(FileItem::forAttachment($photo)['kind'])->toBe('pdf');
    $other = Company::factory()->create();
    $foreign = User::factory()->create(['company_id' => $other->id, 'primary_role' => 'company_admin']);
    $this->actingAs($foreign)->get(route('attachments.download', $photo).'?preview=1')->assertNotFound();
    $this->get(route('attachments.download', $photo))->assertNotFound();
    $photo->mime_type = 'text/plain';
    expect(FileItem::forAttachment($photo)['kind'])->toBe('other')
        ->and(FileItem::forAttachment($photo)['view_url'])->toBeNull();
});
