<?php

namespace App\Notifications;

use App\Models\DisciplinaryCase;
use App\Support\Format\Ui;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CaseDeadlineAlert extends Notification
{
    use Queueable;

    public function __construct(public DisciplinaryCase $case, public string $type) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('messages.notifications.deadline_subject', ['number' => Ui::number($this->case->case_number)]))
            ->line(__($this->messageKey()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'company_id' => $this->case->company_id,
            'case_id' => $this->case->id,
            'alert_type' => $this->type,
            'message_key' => $this->messageKey(),
            'message_params' => [],
        ];
    }

    private function messageKey(): string
    {
        return match ($this->type) {
            'procedure_approaching' => 'messages.notifications.procedure_approaching',
            'procedure_overdue' => 'messages.notifications.procedure_overdue',
            'response_overdue' => 'messages.notifications.response_overdue',
            'consultation_pending' => 'messages.notifications.consultation_pending',
            'appeal_window_ending' => 'messages.notifications.appeal_window_ending',
            default => 'messages.notifications.deadline_default',
        };
    }
}
