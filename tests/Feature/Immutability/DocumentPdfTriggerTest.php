<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentIssuer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('frozen render input and once-only PDF columns are enforced by MySQL', function (): void {
    Queue::fake();
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $draft = Document::factory()->create(['created_by' => $actor->id]);
    foreach (['ar', 'en'] as $language) {
        $draft->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Human body', 'is_authoritative' => $language === 'ar']);
    }
    $issued = app(DocumentIssuer::class)->issue($draft, $actor);
    $row = fn () => DB::table('documents')->where('id', $issued->id);
    expect($issued->rendered_html)->not->toBeNull();
    expect(fn () => $row()->update(['rendered_html' => 'changed']))->toThrow(QueryException::class);
    expect(fn () => $row()->update(['render_snapshot' => '{}']))->toThrow(QueryException::class);
    expect(fn () => DB::table('document_contents')->where('document_id', $issued->id)
        ->update(['secondary_body' => 'changed']))->toThrow(QueryException::class);
    $row()->update(['pdf_status' => 'failed', 'pdf_error' => 'Chrome failed']);
    $row()->update(['pdf_status' => 'pending', 'pdf_error' => null]);
    expect(fn () => $row()->update(['pdf_status' => 'ready']))->toThrow(QueryException::class);
    $row()->update(['pdf_status' => 'ready', 'pdf_file_path' => 'documents/test.pdf',
        'pdf_sha256' => str_repeat('a', 64)]);
    expect(fn () => $row()->update(['pdf_status' => 'failed']))->toThrow(QueryException::class);
    expect(fn () => $row()->update(['pdf_sha256' => str_repeat('b', 64)]))->toThrow(QueryException::class);
});
