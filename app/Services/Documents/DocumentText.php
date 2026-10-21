<?php

namespace App\Services\Documents;

use App\Ai\ClaudeClient;
use App\Ai\ScanBlocks;
use App\Models\Attachment;
use App\Services\EmailIntake\EmailContentSanitizer;
use App\Services\EmailIntake\IntakeMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Webklex\PHPIMAP\Message;

class DocumentText
{
    public function __construct(private MsgReader $messages, private ClaudeClient $claude, private ScanBlocks $blocks) {}

    public function read(Attachment $attachment): array
    {
        $path = Storage::disk($attachment->disk)->path($attachment->path);
        $extension = strtolower(pathinfo($attachment->path, PATHINFO_EXTENSION));
        $result = match ($extension) {
            'eml' => [$this->email($path), null],
            'msg' => [$this->messages->read($path), null],
            'docx' => [$this->word($path), null],
            'pdf' => $this->pdf($attachment, $path),
            'jpg', 'png', 'webp' => $this->vision($attachment),
            default => [null, null],
        };

        return [is_string($result[0]) ? mb_substr(trim($result[0], " \t\n\r\0\x0B\f"), 0, 50000) : null, $result[1]];
    }

    private function email(string $path): string
    {
        $message = Message::fromFile($path);
        $intake = new IntakeMessage(0, filesize($path), (string) $message->getHeader()?->raw,
            CarbonImmutable::now(), (string) $message->getTextBody(), (string) $message->getHTMLBody());
        [$subject, $body] = app(EmailContentSanitizer::class)->content($intake);
        $header = $message->getHeader();

        return implode("\n", array_filter([
            'From: '.(string) ($header?->get('from')?->first() ?? ''),
            'Date: '.(string) ($header?->get('date')?->first() ?? ''),
            'Subject: '.$subject, $body,
        ]));
    }

    private function word(string $path): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open Word document');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if ($xml === false) {
            throw new \RuntimeException('Word document has no body');
        }
        $xml = preg_replace('/<\/w:p>/u', "\n</w:p>", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function pdf(Attachment $attachment, string $path): array
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->run();
        // A scanned PDF's text layer is just form feeds ("\f"), which trim() keeps by default; such a file must go to vision.
        if ($process->isSuccessful() && trim($process->getOutput(), " \t\n\r\0\x0B\f") !== '') {
            return [$process->getOutput(), null];
        }

        return $this->vision($attachment);
    }

    private function vision(Attachment $attachment): array
    {
        $response = $this->claude->structured('read_report_attachment', [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['mostly_text', 'text'],
            'properties' => ['mostly_text' => ['type' => 'boolean'], 'text' => ['type' => 'string']],
        ], [['role' => 'user', 'content' => [
            $this->blocks->for($attachment),
            ['type' => 'text', 'text' => 'Transcribe only visible text in its original language. If this is mainly a scene or object rather than a message, letter, or written note, set mostly_text false and text empty. Do not infer facts.'],
        ]]], ['company_id' => $attachment->company_id, 'attachment_id' => $attachment->id,
            'actor_user_id' => $attachment->uploaded_by],
            (string) config('displanery.ai.report_assistant_model'), 'low');

        return [($response['mostly_text'] ?? false) === true ? (string) ($response['text'] ?? '') : null,
            $response['_generation_log_id'] ?? null];
    }
}
