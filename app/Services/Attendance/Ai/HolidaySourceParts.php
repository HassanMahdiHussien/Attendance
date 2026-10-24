<?php

namespace App\Services\Attendance\Ai;

use App\Models\Attendance\HolidaySource;
use App\Support\Tenancy\TenantContext;
use Exception;
use Hfig\MAPI\MapiMessageFactory;
use Hfig\MAPI\OLE\Pear\DocumentFactory;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Webklex\PHPIMAP\Message;

class HolidaySourceParts
{
    private array $parts = [];

    private array $blocks = [];

    private int $pageCount = 0;

    public function extract(HolidaySource $source): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $source->company_id, 403);
        $this->parts = [];
        $this->blocks = [];
        $this->pageCount = 0;
        $disk = Storage::disk('local');
        $bytes = $disk->get($source->file_path);
        $type = strtolower(pathinfo($source->file_name, PATHINFO_EXTENSION));
        if ($type === 'pdf') {
            $this->pdf($source, $bytes, 'announcement.pdf');
        } elseif (in_array($type, ['png', 'jpg', 'jpeg'], true)) {
            $this->image($source, $bytes, 'announcement.'.$type);
        } elseif ($type === 'eml') {
            $message = Message::fromString($bytes);
            $this->body($source, $message->getTextBody() ?: strip_tags($message->getHTMLBody()));
            foreach ($message->getAttachments() as $index => $attachment) {
                $this->attachment($source, (string) $attachment->getContent(),
                    (string) ($attachment->getName() ?: 'attachment-'.$index), (string) $attachment->getContentType());
            }
        } elseif ($type === 'msg') {
            $path = $disk->path($source->file_path);
            $message = (new MapiMessageFactory)->parseMessage((new DocumentFactory)->createFromFile($path));
            try {
                $body = (string) $message->getBody();
            } catch (Exception) {
                $body = strip_tags((string) $message->getBodyHTML());
            }
            $this->body($source, $body);
            foreach ($message->getAttachments() as $index => $attachment) {
                $data = $attachment->getData();
                if (is_string($data)) {
                    $this->attachment($source, $data, $attachment->getFilename() ?: 'attachment-'.$index,
                        $attachment->getMimeType());
                }
            }
        } else {
            $this->invalid('unsupported_file');
        }

        if ($this->blocks === []) {
            $this->invalid('empty_file');
        }
        if (strlen(json_encode($this->blocks, JSON_THROW_ON_ERROR)) > 30 * 1024 * 1024) {
            $this->invalid('request_too_large');
        }

        return ['parts' => $this->parts, 'blocks' => $this->blocks, 'pages' => $this->pageCount];
    }

    private function attachment(HolidaySource $source, string $bytes, string $name, string $mime): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($mime === 'application/pdf' || $extension === 'pdf') {
            $this->pdf($source, $bytes, $name);
        } elseif (str_starts_with($mime, 'image/') || in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            $this->image($source, $bytes, $name);
        }
    }

    private function body(HolidaySource $source, string $text): void
    {
        if (trim($text) === '') {
            return;
        }
        $path = $this->store($source, 'body.txt', $text);
        $this->parts[] = ['kind' => 'text', 'name' => 'body.txt', 'path' => $path];
        $this->blocks[] = ['type' => 'text', 'text' => $text];
    }

    private function image(HolidaySource $source, string $bytes, string $name): void
    {
        $details = @getimagesizefromstring($bytes);
        if ($details === false || ! in_array($details['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)
            || strlen($bytes) > 5 * 1024 * 1024 || $details[0] > 8000 || $details[1] > 8000) {
            $this->invalid('invalid_image');
        }
        if ($details['mime'] === 'image/gif') {
            $image = imagecreatefromstring($bytes);
            if ($image === false) {
                $this->invalid('invalid_image');
            }
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024) {
                $this->invalid('invalid_image');
            }
            $name = pathinfo($name, PATHINFO_FILENAME).'.png';
            $details['mime'] = 'image/png';
        }
        $path = $this->store($source, basename($name), $bytes);
        $this->parts[] = ['kind' => 'image', 'name' => basename($name), 'path' => $path];
        $this->blocks[] = ['type' => 'image', 'source' => [
            'type' => 'base64', 'media_type' => $details['mime'], 'data' => base64_encode($bytes),
        ]];
    }

    private function pdf(HolidaySource $source, string $bytes, string $name): void
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            $this->invalid('invalid_pdf');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'holiday-pdf-');
        file_put_contents($tmp, $bytes);
        try {
            $info = new Process(['pdfinfo', $tmp]);
            $info->run();
            $output = $info->getOutput().$info->getErrorOutput();
            if (preg_match('/Encrypted:\s+yes/i', $output) || str_contains(strtolower($output), 'password')) {
                $this->invalid('encrypted_pdf');
            }
            if (! $info->isSuccessful() || ! preg_match('/Pages:\s+(\d+)/i', $output, $match)) {
                $this->invalid('invalid_pdf');
            }
            $pages = (int) $match[1];
            if ($pages < 1 || $pages > 100 || $this->pageCount + $pages > 100) {
                $this->invalid('too_many_pages');
            }
            $this->pageCount += $pages;
            $this->blocks[] = ['type' => 'document', 'source' => [
                'type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes),
            ]];
            $path = $this->store($source, basename($name), $bytes);
            $this->parts[] = ['kind' => 'pdf', 'name' => basename($name), 'path' => $path];
            for ($page = 1; $page <= $pages; $page++) {
                $prefix = tempnam(sys_get_temp_dir(), 'holiday-page-');
                @unlink($prefix);
                $render = new Process(['pdftoppm', '-f', (string) $page, '-l', (string) $page,
                    '-singlefile', '-r', '110', '-png', $tmp, $prefix]);
                $render->run();
                $rendered = $prefix.'.png';
                if (! $render->isSuccessful() || ! is_file($rendered)) {
                    $this->invalid('pdf_render_failed');
                }
                $pagePath = $this->store($source, 'page-'.$page.'.png', file_get_contents($rendered));
                @unlink($rendered);
                $this->parts[] = ['kind' => 'page', 'name' => basename($name), 'page' => $page, 'path' => $pagePath];
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function store(HolidaySource $source, string $name, string $bytes): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = 'attendance/holidays/'.$source->company_id.'/'.$source->id.'/parts/'
            .str_pad((string) (count($this->parts) + 1), 4, '0', STR_PAD_LEFT).'.'.$extension;
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('part_storage_failed');
        }

        return $path;
    }

    private function invalid(string $code): never
    {
        throw new RuntimeException($code);
    }
}
