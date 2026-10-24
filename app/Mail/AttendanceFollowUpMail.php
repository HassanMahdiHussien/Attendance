<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

class AttendanceFollowUpMail extends Mailable
{
    public function __construct(public array $entries, public string $language, public string $messageUuid)
    {
        $this->locale($language);
    }

    public function build(): self
    {
        $this->withSymfonyMessage(function (Email $message): void {
            $message->getHeaders()->addTextHeader('X-Attendance-Message', $this->messageUuid);
        });

        return $this->subject(__('messages.follow_up_mail.subject'))
            ->view('mail.attendance-follow-up', ['entries' => $this->entries, 'language' => $this->language]);
    }
}
