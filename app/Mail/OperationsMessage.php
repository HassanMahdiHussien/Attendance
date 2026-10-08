<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class OperationsMessage extends Mailable
{
    public function __construct(public string $subjectLine, public string $messageText) {}

    public function build(): self
    {
        return $this->subject($this->subjectLine)->text('mail.operations-message', ['messageText' => $this->messageText]);
    }
}
