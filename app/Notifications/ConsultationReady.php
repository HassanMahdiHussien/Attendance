<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConsultationReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $caseId)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('messages.notifications.consultation_ready_subject'))
            ->line(__('messages.notifications.consultation_ready_body'));
    }

    public function toArray(object $notifiable): array
    {
        return ['case_id' => $this->caseId, 'kind' => 'consultation_ready',
            'message_key' => 'messages.notifications.consultation_ready_body', 'message_params' => []];
    }
}
