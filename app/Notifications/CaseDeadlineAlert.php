<?php

namespace App\Notifications;

use App\Models\DisciplinaryCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CaseDeadlineAlert extends Notification
{
    use Queueable;

    public function __construct(public DisciplinaryCase $case, public string $type)
    {
        $this->locale('ar');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('تنبيه موعد القضية رقم '.$this->case->case_number)
            ->line($this->message());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'company_id' => $this->case->company_id,
            'case_id' => $this->case->id,
            'alert_type' => $this->type,
            'message' => $this->message(),
        ];
    }

    private function message(): string
    {
        return match ($this->type) {
            'procedure_approaching' => 'اقترب الموعد النهائي للإجراء التأديبي.',
            'procedure_overdue' => 'تجاوزت القضية الموعد النهائي للإجراء التأديبي.',
            'response_overdue' => 'انتهت مهلة رد الموظف.',
            'consultation_pending' => 'الاستشارة القانونية المطلوبة لم تكتمل والمهلة مستمرة.',
            'appeal_window_ending' => 'تقترب نهاية مهلة الطعن.',
            default => 'تنبيه بشأن موعد القضية.',
        };
    }
}
