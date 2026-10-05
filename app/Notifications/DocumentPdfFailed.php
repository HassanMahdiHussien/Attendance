<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentPdfFailed extends Notification
{
    use Queueable;

    public function __construct(public int $documentId, public string $error) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('تعذر إنشاء ملف PDF للمستند')->line("المستند {$this->documentId}: {$this->error}");
    }

    public function toArray(object $notifiable): array
    {
        return ['document_id' => $this->documentId, 'error' => $this->error];
    }
}
