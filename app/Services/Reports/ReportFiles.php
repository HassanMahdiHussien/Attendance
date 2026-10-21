<?php

namespace App\Services\Reports;

use App\Enums\AttachmentKind;
use App\Models\Attachment;
use App\Models\ReportAssistantSession;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReportFiles
{
    public function active(ReportAssistantSession $session): Collection
    {
        $removed = $session->facts['removed_attachment_ids'] ?? [];

        return $session->attachments()->with('textReading')->orderBy('id')->get()
            ->reject(fn (Attachment $file): bool => in_array($file->id, $removed, true))->values();
    }

    public function add(ReportAssistantSession $session, array $files, User $actor): void
    {
        abort_unless((int) $session->user_id === (int) $actor->id && $session->violation_report_id === null, 403);
        if ($this->active($session)->count() + count($files) > 10) {
            throw ValidationException::withMessages(['attachments' => __('Maximum 10 files.')]);
        }
        foreach ($files as $file) {
            abort_unless($file instanceof UploadedFile, 422);
            app(AttachmentService::class)->store($session, AttachmentKind::ReportEvidence, $file, $actor);
        }
    }

    public function remove(ReportAssistantSession $session, int $id, User $actor): void
    {
        abort_unless((int) $session->user_id === (int) $actor->id && $session->violation_report_id === null, 403);
        abort_unless($this->active($session)->contains('id', $id), 404);
        $facts = $session->facts;
        $facts['removed_attachment_ids'][] = $id;
        $session->update(['facts' => $facts]);
    }

    public function send(ReportAssistantSession $session, ViolationReport $report): void
    {
        abort_unless((int) $session->company_id === (int) $report->company_id, 404);
        foreach ($this->active($session) as $file) {
            app(AttachmentService::class)->copyEvidence($file, $report);
        }
    }
}
