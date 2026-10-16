<?php

namespace App\Support\Help;

final readonly class HelpArticle
{
    public function __construct(
        public string $locale,
        public string $section,
        public string $slug,
        public string $title,
        public string $summary,
        public array $audience,
        public int $order,
        public string $markdown,
        public string $html,
    ) {}

    public function url(): string
    {
        return route('help.article', [$this->section, $this->slug]);
    }
}
