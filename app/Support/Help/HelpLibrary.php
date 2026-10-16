<?php

namespace App\Support\Help;

use App\Enums\UserRole;
use App\Support\Search\SearchText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class HelpLibrary
{
    private const SECTIONS = ['getting-started', 'reporting', 'hr', 'committee', 'legal', 'partner', 'admin', 'platform', 'rules', 'faq'];

    public function __construct(private readonly ?string $root = null) {}

    public function sections(): array
    {
        return array_map(fn (string $slug): array => ['slug' => $slug, 'name' => __(match ($slug) {
            'getting-started' => 'Getting started', 'reporting' => 'Reporting', 'hr' => 'HR',
            'committee' => 'Committee', 'legal' => 'Legal', 'partner' => 'Partner',
            'admin' => 'Administration', 'platform' => 'Platform', 'rules' => 'Rules', 'faq' => 'FAQ',
        })], self::SECTIONS);
    }

    public function all(string $locale): array
    {
        if (! in_array($locale, ['en', 'ar'], true)) {
            return [];
        }

        $files = glob($this->path().'/'.$locale.'/*/*.md') ?: [];
        sort($files);
        $fingerprint = hash('sha256', implode('|', array_map(fn (string $file): string => str_replace('\\', '/', substr($file, strlen($this->path()))).':'.filesize($file).':'.hash_file('sha256', $file), $files)));
        $read = function () use ($files, $locale): array {
            $articles = array_map(fn (string $file): HelpArticle => $this->parse($file, $locale), $files);
            usort($articles, fn (HelpArticle $a, HelpArticle $b): int => [array_search($a->section, self::SECTIONS, true), $a->order, $a->slug] <=> [array_search($b->section, self::SECTIONS, true), $b->order, $b->slug]);

            return $articles;
        };

        return app()->environment(['local', 'testing']) || $this->root !== null
            ? $read() : Cache::rememberForever('help.'.$locale.'.'.$fingerprint, $read);
    }

    public function find(string $locale, string $section, string $slug): ?HelpArticle
    {
        foreach ($this->all($locale) as $article) {
            if ($article->section === $section && $article->slug === $slug) {
                return $article;
            }
        }

        return null;
    }

    public function forRole(string $locale, UserRole $role): array
    {
        return array_values(array_filter($this->all($locale), fn (HelpArticle $article): bool => in_array('all', $article->audience, true) || in_array($role->value, $article->audience, true)));
    }

    public function search(string $locale, string $query, ?UserRole $role = null): array
    {
        $needle = SearchText::normalize(trim($query));
        $articles = $role ? $this->forRole($locale, $role) : $this->all($locale);
        if ($needle === '') {
            return $articles;
        }

        return array_values(array_filter($articles, fn (HelpArticle $article): bool => str_contains(SearchText::normalize($article->title.' '.$article->summary.' '.strip_tags($article->html)), $needle)));
    }

    private function path(): string
    {
        return $this->root ?? resource_path('help');
    }

    private function parse(string $file, string $locale): HelpArticle
    {
        $source = file_get_contents($file);
        // Unicode mode: without /u, \R treats the 0x85 byte inside Arabic letters as a line break.
        if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/su', $source, $parts)) {
            throw new RuntimeException("Invalid help front matter: {$file}");
        }
        $meta = [];
        foreach (preg_split('/\R/u', $parts[1]) as $line) {
            if (! preg_match('/\A(title|summary|audience|order):\s*(.*?)\s*\z/u', $line, $match)) {
                throw new RuntimeException("Invalid help front matter: {$file}");
            }
            $meta[$match[1]] = $match[2];
        }
        foreach (['title', 'summary', 'audience', 'order'] as $key) {
            if (empty($meta[$key])) {
                throw new RuntimeException("Missing {$key}: {$file}");
            }
        }
        if (! ctype_digit($meta['order'])) {
            throw new RuntimeException("Invalid order: {$file}");
        }
        $audience = array_map('trim', explode(',', $meta['audience']));
        if (array_diff($audience, array_merge(['all'], array_column(UserRole::cases(), 'value'))) || (in_array('all', $audience, true) && count($audience) !== 1)) {
            throw new RuntimeException("Invalid audience: {$file}");
        }
        $section = basename(dirname($file));
        $slug = pathinfo($file, PATHINFO_FILENAME);
        if (! in_array($section, self::SECTIONS, true) || ! preg_match('/\A[a-z0-9-]+\z/', $slug)) {
            throw new RuntimeException("Invalid help path: {$file}");
        }
        $markdown = $parts[2];
        $markdown = preg_replace_callback('/(!?\[[^\]\r\n]*\]\()(help:([a-z0-9-]+)\/([a-z0-9-]+)|shots\/([a-z0-9-]+))(\))/u', function (array $match) use ($locale): string {
            $target = isset($match[5]) && $match[5] !== ''
                ? route('help.shot', [$locale, $match[5]])
                : route('help.article', [$match[3], $match[4]]);

            return $match[1].$target.$match[6];
        }, $markdown);
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return new HelpArticle($locale, $section, $slug, $meta['title'], $meta['summary'], $audience, (int) $meta['order'], $parts[2], $html);
    }
}
