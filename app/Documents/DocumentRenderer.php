<?php

namespace App\Documents;

use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Models\CompanyLetterhead;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class DocumentRenderer
{
    private array $snapshot = [];

    public function __construct(private DocumentFacts $collector, private TemplateResolver $templates,
        private PlaceholderResolver $placeholders, private BodyFormatter $formatter, private CitationRenderer $citations,
        private CitationGate $citationGate) {}

    public function snapshot(): array
    {
        return $this->snapshot;
    }

    public function render(Document $document, bool $draft = false): string
    {
        $document->load(['contents', 'disciplinaryCase.employee', 'company']);
        $this->citationGate->assertIssuable($document);
        $pages = [];
        $this->snapshot = ['renderer' => 'v1', 'template_versions' => [], 'template_ids' => [],
            'letterhead_versions' => [], 'letterhead_ids' => [], 'logo_sha256' => []];
        // Every enabled language is printed; a missing Kurdish template or letterhead blocks issuance rather than
        // silently dropping the language (DraftLanguages narrows only what the AI is asked to draft).
        $languages = array_values(array_unique(array_merge(['ar', 'en'], $document->company->enabled_languages)));
        foreach ($languages as $language) {
            $content = $document->contents->firstWhere('language_code', $language);
            if (! $content || blank($content->body)) {
                throw new MissingDocumentFact("content.{$language}.body");
            }
            if ($document->document_type === DocumentType::Recommendations && blank($content->secondary_body)) {
                throw new MissingDocumentFact("content.{$language}.secondary_body");
            }
            $letterhead = CompanyLetterhead::query()->where('company_id', $document->company_id)
                ->where('language_code', $language)->orderByDesc('version')->first();
            if (! $letterhead) {
                throw new MissingDocumentFact("letterhead.{$language}");
            }
            $template = $this->templates->resolve($document->company_id, $document->document_type->value, $language);
            [$facts, $extra, $gender] = $this->collector->collect($document, $language, $draft);
            $facts['company_name'] = $letterhead->name;
            $blocks = [];
            foreach ($template['blocks'] as $key => $value) {
                $blocks[$key] = $this->placeholders->resolve($value, $facts, $gender);
            }
            if ($document->document_type === DocumentType::Recommendations) {
                $defaultPath = app_path("Documents/Defaults/recommendations.{$language}.php");
                $defaults = is_file($defaultPath) ? require $defaultPath : [];
                foreach (['chair_label_female', 'member_label_female'] as $key) {
                    if (blank($blocks[$key] ?? null)) {
                        $blocks[$key] = $defaults[$key] ?? $blocks[str_replace('_female', '', $key)];
                    }
                }
            }
            $logo = $this->dataUri($letterhead->logo_path, $letterhead->logo_sha256);
            $headerImage = $this->dataUri($letterhead->header_image_path, $letterhead->header_image_sha256);
            if ($letterhead->header_mode === 'image' && ! $headerImage) {
                throw new MissingDocumentFact("letterhead.{$language}.header_image");
            }
            $body = $this->formatter->format($this->citations->replace($document, $content->body, $language));
            $secondary = $content->secondary_body === null ? null
                : $this->formatter->format($this->citations->replace($document, $content->secondary_body, $language));
            $pages[] = compact('language', 'letterhead', 'blocks', 'facts', 'extra', 'body', 'secondary', 'logo', 'headerImage')
                + ['options' => array_replace($template['options'], ['header_mode' => $letterhead->header_mode]), 'type' => $document->document_type->value];
            $this->snapshot['template_versions'][$language] = $template['version'];
            $this->snapshot['template_ids'][$language] = $template['id'];
            $this->snapshot['letterhead_versions'][$language] = $letterhead->version;
            $this->snapshot['letterhead_ids'][$language] = $letterhead->id;
            $this->snapshot['logo_sha256'][$language] = $letterhead->logo_sha256;
        }

        return view('documents.layout', ['pages' => $pages, 'draft' => $draft])->render();
    }

    private function dataUri(?string $path, ?string $expectedSha): ?string
    {
        if (! $path) {
            return null;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            throw new IssuanceBlocked("صورة الترويسة {$path} غير موجودة.");
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => null
        };
        if (! $mime) {
            throw new IssuanceBlocked('يجب أن تكون صورة الترويسة بصيغة PNG أو JPG.');
        }

        $bytes = $disk->get($path);
        if (strlen($bytes) > 2 * 1024 * 1024
            || ($mime === 'image/png' && ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n"))
            || ($mime === 'image/jpeg' && ! str_starts_with($bytes, "\xFF\xD8"))) {
            throw new IssuanceBlocked("صورة الترويسة {$path} غير صحيحة.");
        }
        if (! $expectedSha || hash('sha256', $bytes) !== $expectedSha) {
            throw new IssuanceBlocked("بصمة صورة الترويسة {$path} غير مطابقة.");
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
