<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class OperationsMessage extends Mailable
{
    public function __construct(public string $subjectLine, public string $messageText)
    {
        $this->locale('en');
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->markdown('mail.operations-message-html', ['messageText' => $this->messageText]);
    }
}
