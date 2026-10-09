<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\EmailDraft;
use App\Models\ViolationReport;
use App\Support\Access\CaseVisibility;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, string $attachment): BinaryFileResponse
    {
        $attachment = Attachment::withoutGlobalScopes()->findOrFail($attachment);
        $user = $request->user();
        $parent = match ($attachment->attachable_type) {
            'case' => DisciplinaryCase::withoutGlobalScopes()->find($attachment->attachable_id),
            'document' => Document::withoutGlobalScopes()->find($attachment->attachable_id),
            'violation_report' => ViolationReport::withoutGlobalScopes()->find($attachment->attachable_id),
            'email_draft' => EmailDraft::withoutGlobalScopes()->find($attachment->attachable_id),
            default => null,
        };
        $visible = match (true) {
            $parent instanceof DisciplinaryCase => CaseVisibility::for($user)->whereKey($parent->id)->exists(),
            $parent instanceof EmailDraft => EmailDraftVisibility::inbox($user)->whereKey($parent->id)->exists(),
            $parent instanceof Document => CaseVisibility::for($user)->whereKey($parent->case_id)->exists(),
            $parent instanceof ViolationReport => $parent->case_id !== null
                ? CaseVisibility::for($user)->whereKey($parent->case_id)->exists()
                : in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                    && (int) $parent->company_id === (int) $user->company_id
                    && ViolationReportVisibility::inbox($user)->whereKey($parent->id)->exists(),
            default => false,
        };
        abort_unless($visible && $parent !== null && (int) $attachment->company_id === (int) $parent->company_id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        if ($request->boolean('preview') && in_array($attachment->mime_type, ['image/png', 'image/jpeg', 'application/pdf'], true)) {
            return response()->file(Storage::disk($attachment->disk)->path($attachment->path),
                ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff',
                    'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'",
                    'X-Frame-Options' => 'SAMEORIGIN'])
                // BinaryFileResponse defaults to public caching; case files must never sit in a shared cache.
                ->setCache(['no_store' => true, 'private' => true]);
        }

        return response()->download(Storage::disk($attachment->disk)->path($attachment->path), $attachment->original_name,
            ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }
}
