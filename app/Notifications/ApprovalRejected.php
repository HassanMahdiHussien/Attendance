<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $caseId, public int $documentId)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('messages.notifications.approval_rejected_subject'))
            ->line(__('messages.notifications.approval_rejected_body'));
    }

    public function toArray(object $notifiable): array
    {
        return ['case_id' => $this->caseId, 'document_id' => $this->documentId, 'kind' => 'approval_rejected',
            'message_key' => 'messages.notifications.approval_rejected_body', 'message_params' => []];
    }
}
