<?php

namespace App\Services\EmailIntake;

use DOMDocument;
use DOMNode;

class EmailContentSanitizer
{
    public function content(IntakeMessage $message): array
    {
        $body = $message->plainText !== '' ? $message->plainText : $this->htmlToText($message->html);
        $body = $this->clean($body);
        $truncated = mb_strlen($body) > 50000;
        if ($truncated) {
            $body = mb_substr($body, 0, 50000);
        }
        $headers = app(EmailSenderVerifier::class)->headers($message->rawHeaders);
        $subject = iconv_mime_decode($headers['subject'][0] ?? '', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return [mb_substr($this->clean($subject === false ? '' : $subject), 0, 255), $body, $truncated];
    }

    private function clean(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
    }

    private function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach (['script', 'style', 'noscript', 'head'] as $tag) {
            while ($document->getElementsByTagName($tag)->length) {
                $node = $document->getElementsByTagName($tag)->item(0);
                $node->parentNode->removeChild($node);
            }
        }
        $walk = function (DOMNode $node) use (&$walk): string {
            if ($node->nodeType === XML_TEXT_NODE) {
                return $node->nodeValue;
            }
            $text = '';
            foreach ($node->childNodes as $child) {
                $text .= $walk($child);
            }

            return in_array(mb_strtolower($node->nodeName), ['p', 'div', 'br', 'li', 'tr', 'h1', 'h2', 'h3'], true)
                ? $text."\n" : $text;
        };

        return $document->documentElement ? $walk($document->documentElement) : '';
    }
}
