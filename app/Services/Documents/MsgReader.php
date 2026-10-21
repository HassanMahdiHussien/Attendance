<?php

namespace App\Services\Documents;

use Hfig\MAPI;
use Hfig\MAPI\OLE\Pear;

class MsgReader
{
    public function read(string $path): string
    {
        $message = (new MAPI\MapiMessageFactory)->parseMessage((new Pear\DocumentFactory)->createFromFile($path));
        $date = $message->properties['client_submit_time'] ?? $message->properties['message_delivery_time'] ?? null;

        return implode("\n", array_filter([
            'From: '.(string) $message->getSender(),
            $date ? 'Date: '.($date instanceof \DateTimeInterface ? $date->format(DATE_ATOM) : (string) $date) : null,
            'Subject: '.(string) ($message->properties['subject'] ?? ''),
            (string) $message->getBody(),
        ]));
    }
}
