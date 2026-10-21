<?php

namespace App\Services\Attachments;

use App\Enums\AttachmentKind;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\CaseFileIntake;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\EmailDraft;
use App\Models\ReportAssistantSession;
use App\Models\User;
use App\Models\ViolationReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttachmentService
{
    public function store(DisciplinaryCase|ViolationReport|Document|EmailDraft|ReportAssistantSession|CaseFileIntake $parent, AttachmentKind $kind, UploadedFile $file, User $actor): Attachment
    {
        if ($parent instanceof DisciplinaryCase || $parent instanceof Document) {
            Gate::forUser($actor)->authorize('uploadAttachment', $parent instanceof Document ? $parent->disciplinaryCase : $parent);
            $allowed = [AttachmentKind::EmployeeResponseScan, AttachmentKind::DeliveryProof, AttachmentKind::SignedDocumentScan,
                AttachmentKind::ReportEvidence];
            if ($parent instanceof Document && ($kind !== AttachmentKind::SignedDocumentScan || ! $parent->is_locked)) {
                throw ValidationException::withMessages(['attachments' => __('messages.attachment.type_not_allowed')]);
            }
        } elseif ($parent instanceof ReportAssistantSession || $parent instanceof CaseFileIntake) {
            abort_unless((int) $parent->user_id === (int) $actor->id
                && (int) $parent->company_id === (int) $actor->company_id
                && ($parent instanceof CaseFileIntake || $parent->violation_report_id === null), 403);
            $allowed = [AttachmentKind::ReportEvidence];
        } elseif ($parent instanceof EmailDraft) {
            abort_unless((int) $parent->sender_user_id === (int) $actor->id
                && (int) $parent->company_id === (int) $actor->company_id, 403);
            $allowed = [AttachmentKind::ReportEvidence];
        } else {
            abort_unless($actor->is_active && $actor->company?->is_active
                && ($actor->primary_role === UserRole::Reporter || $actor->primary_role === UserRole::DeptManager), 403);
            abort_unless((int) $parent->reporter_user_id === (int) $actor->id && (int) $parent->company_id === (int) $actor->company_id, 403);
            $allowed = [AttachmentKind::ReportEvidence];
        }
        if (! in_array($kind, $allowed, true)) {
            throw ValidationException::withMessages(['attachments' => __('messages.attachment.type_or_size_not_allowed')]);
        }
        $nameExtension = strtolower($file->getClientOriginalExtension());
        if ($parent instanceof CaseFileIntake && in_array($nameExtension, ['mp4', 'mov'], true)) {
            throw ValidationException::withMessages(['caseFiles' => __('messages.attachment.case_file_type_or_size_not_allowed')]);
        }
        $sizeLimit = in_array($nameExtension, ['mp4', 'mov'], true) ? 50 : 10;
        if ($file->getSize() > $sizeLimit * 1024 * 1024) {
            throw ValidationException::withMessages([($parent instanceof CaseFileIntake ? 'caseFiles' : 'attachments') => __($parent instanceof CaseFileIntake ? 'messages.attachment.case_file_type_or_size_not_allowed' : 'messages.attachment.type_or_size_not_allowed')]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extension = $kind === AttachmentKind::ReportEvidence && ! $parent instanceof EmailDraft
            ? $this->evidenceExtension($file, $mime)
            : match ($mime) {
                'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
                default => null,
            };
        if ($extension === null) {
            $message = $kind === AttachmentKind::ReportEvidence && ! $parent instanceof EmailDraft
                ? 'messages.attachment.type_or_size_not_allowed' : 'messages.attachment.pdf_jpg_png_only';
            throw ValidationException::withMessages([($parent instanceof CaseFileIntake ? 'caseFiles' : 'attachments') => __($parent instanceof CaseFileIntake ? 'messages.attachment.case_file_type_or_size_not_allowed' : $message)]);
        }
        $digest = hash_file('sha256', $file->getRealPath());
        $path = $parent instanceof EmailDraft
            ? 'attachments/'.$parent->company_id.'/email-drafts/'.$parent->id.'/'.(string) Str::uuid().'.'.$extension
            : 'attachments/'.$parent->company_id.'/'.$digest.'.'.$extension;
        $disk = Storage::disk('local');
        $createdFile = ! $disk->exists($path);
        if ($createdFile) {
            if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
                $disk->delete($path);
                throw ValidationException::withMessages(['attachments' => __('messages.attachment.save_failed')]);
            }
        }
        try {
            return Attachment::create([
                'company_id' => $parent->company_id,
                'attachable_type' => $parent->getMorphClass(), 'attachable_id' => $parent->id,
                'kind' => $kind, 'disk' => 'local', 'path' => $path,
                'original_name' => $file->getClientOriginalName(), 'mime_type' => $mime,
                'size_bytes' => $file->getSize(), 'uploaded_by' => $actor->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            if ($createdFile) {
                $disk->delete($path);
            }
            throw $exception;
        }
    }

    private function evidenceExtension(UploadedFile $file, string $mime): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        $prefix = file_get_contents($path, false, null, 0, 32);

        return match ($extension) {
            'pdf' => $mime === 'application/pdf' ? 'pdf' : null,
            'jpg', 'jpeg' => $mime === 'image/jpeg' ? 'jpg' : null,
            'png' => $mime === 'image/png' ? 'png' : null,
            'webp' => str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WEBP' ? 'webp' : null,
            'eml' => in_array($mime, ['text/plain', 'message/rfc822'], true)
                && preg_match('/^(?:From|To|Date|Subject|MIME-Version):/mi', file_get_contents($path)) ? 'eml' : null,
            'msg' => str_starts_with($prefix, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") ? 'msg' : null,
            'docx' => $this->isDocx($path) ? 'docx' : null,
            'mp4', 'mov' => substr($prefix, 4, 4) === 'ftyp' ? $extension : null,
            default => null,
        };
    }

    private function isDocx(string $path): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $valid;
    }

    public function copyEvidence(Attachment $source, DisciplinaryCase|ViolationReport $parent): Attachment
    {
        abort_unless((int) $source->company_id === (int) $parent->company_id
            && $source->kind === AttachmentKind::ReportEvidence, 404);

        $copy = Attachment::create([
            'company_id' => $parent->company_id, 'attachable_type' => $parent->getMorphClass(),
            'attachable_id' => $parent->id, 'kind' => AttachmentKind::ReportEvidence,
            'disk' => $source->disk, 'path' => $source->path, 'original_name' => $source->original_name,
            'mime_type' => $source->mime_type, 'size_bytes' => $source->size_bytes,
            'uploaded_by' => $source->uploaded_by, 'created_at' => now(),
        ]);
        if ($source->textReading !== null) {
            $copy->textReading()->create([
                'company_id' => $parent->company_id, 'extracted_text' => $source->textReading->extracted_text,
                'reading_status' => $source->textReading->reading_status,
                'ai_generation_log_id' => $source->textReading->ai_generation_log_id,
                'created_at' => now(),
            ]);
        }

        return $copy;
    }

    public function storeEmailBytes(EmailDraft $draft, string $name, string $content, User $sender): Attachment
    {
        $temp = tempnam(sys_get_temp_dir(), 'intake-');
        try {
            file_put_contents($temp, $content);
            $file = new UploadedFile($temp, $name, null, null, true);

            return $this->store($draft, AttachmentKind::ReportEvidence, $file, $sender);
        } finally {
            @unlink($temp);
        }
    }

    public function copyEmailEvidence(Attachment $source, DisciplinaryCase $case, array &$copiedPaths): Attachment
    {
        abort_unless((int) $source->company_id === (int) $case->company_id, 404);
        $disk = Storage::disk('local');
        $path = 'attachments/'.$case->company_id.'/cases/'.$case->id.'/'.(string) Str::uuid().'.'.pathinfo($source->path, PATHINFO_EXTENSION);
        $copiedPaths[] = $path;
        if (! $disk->copy($source->path, $path)) {
            throw new \RuntimeException('Attachment copy failed');
        }

        return Attachment::create([
            'company_id' => $case->company_id, 'attachable_type' => $case->getMorphClass(),
            'attachable_id' => $case->id, 'kind' => AttachmentKind::ReportEvidence,
            'disk' => 'local', 'path' => $path, 'original_name' => $source->original_name,
            'mime_type' => $source->mime_type, 'size_bytes' => $source->size_bytes,
            'uploaded_by' => $source->uploaded_by, 'created_at' => now(),
        ]);
    }
}
