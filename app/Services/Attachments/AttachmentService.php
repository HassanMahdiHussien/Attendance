<?php

namespace App\Services\Attachments;

use App\Enums\AttachmentKind;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\EmailDraft;
use App\Models\User;
use App\Models\ViolationReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttachmentService
{
    public function store(DisciplinaryCase|ViolationReport|Document|EmailDraft $parent, AttachmentKind $kind, UploadedFile $file, User $actor): Attachment
    {
        if ($parent instanceof DisciplinaryCase || $parent instanceof Document) {
            Gate::forUser($actor)->authorize('uploadAttachment', $parent instanceof Document ? $parent->disciplinaryCase : $parent);
            $allowed = [AttachmentKind::EmployeeResponseScan, AttachmentKind::DeliveryProof, AttachmentKind::SignedDocumentScan,
                AttachmentKind::ReportEvidence];
            if ($parent instanceof Document && ($kind !== AttachmentKind::SignedDocumentScan || ! $parent->is_locked)) {
                throw ValidationException::withMessages(['attachments' => 'نوع المرفق غير مسموح.']);
            }
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
        if (! in_array($kind, $allowed, true) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'نوع المرفق أو حجمه غير مسموح.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
            default => null,
        };
        if ($extension === null) {
            throw ValidationException::withMessages(['attachments' => 'يسمح بملفات PDF و JPG و PNG فقط.']);
        }
        $digest = hash_file('sha256', $file->getRealPath());
        $path = $parent instanceof EmailDraft
            ? 'attachments/'.$parent->company_id.'/email-drafts/'.$parent->id.'/'.(string) \Illuminate\Support\Str::uuid().'.'.$extension
            : 'attachments/'.$parent->company_id.'/'.$digest.'.'.$extension;
        $disk = Storage::disk('local');
        $createdFile = ! $disk->exists($path);
        if ($createdFile) {
            if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
                $disk->delete($path);
                throw ValidationException::withMessages(['attachments' => 'تعذر حفظ المرفق.']);
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
        $path = 'attachments/'.$case->company_id.'/cases/'.$case->id.'/'.(string) \Illuminate\Support\Str::uuid().'.'.pathinfo($source->path, PATHINFO_EXTENSION);
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
