<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $documentId)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('توصيات بانتظار قرارك')->line('لديك توصيات لجنة بانتظار مراجعتك في النظام.');
    }

    public function toArray(object $notifiable): array
    {
        return ['document_id' => $this->documentId, 'kind' => 'approval_needed'];
    }
}
