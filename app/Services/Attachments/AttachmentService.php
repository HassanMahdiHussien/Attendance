<?php

namespace App\Services\Attachments;

use App\Enums\AttachmentKind;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Models\ViolationReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttachmentService
{
    public function store(DisciplinaryCase|ViolationReport $parent, AttachmentKind $kind, UploadedFile $file, User $actor): Attachment
    {
        if ($parent instanceof DisciplinaryCase) {
            Gate::forUser($actor)->authorize('uploadAttachment', $parent);
            $allowed = [AttachmentKind::EmployeeResponseScan, AttachmentKind::DeliveryProof, AttachmentKind::SignedDocumentScan];
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
        $path = 'attachments/'.$parent->company_id.'/'.$digest.'.'.$extension;
        $disk = Storage::disk('local');
        $createdFile = ! $disk->exists($path);
        if ($createdFile) {
            if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
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
}
