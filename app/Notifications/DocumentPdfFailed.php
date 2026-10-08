<?php

namespace App\Notifications;

use App\Support\Format\Ui;
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
        return (new MailMessage)->subject(__('messages.notifications.pdf_failed_subject'))
            ->line(__('messages.notifications.pdf_failed_body', ['document' => Ui::number($this->documentId), 'error' => $this->error]));
    }

    public function toArray(object $notifiable): array
    {
        return ['document_id' => $this->documentId, 'error' => $this->error,
            'message_key' => 'messages.notifications.pdf_failed_body',
            'message_params' => ['document' => $this->documentId, 'error' => $this->error]];
    }
}
