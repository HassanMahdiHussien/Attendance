<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DraftPreview
{
    public function refresh(Document $document, User $user, array $bodies, array $secondary = []): array
    {
        $document->loadMissing('company', 'contents');
        $saved = $document->contents->keyBy('language_code');
        $contents = [];
        $dirty = false;
        foreach (array_unique(array_merge(['ar', 'en'], $document->company->enabled_languages)) as $language) {
            $body = $bodies[$language] ?? '';
            $dirty = $dirty || $body !== ($saved[$language]?->body ?? '');
            $contents[$language] = ['body' => $body];
            if ($document->document_type->value === 'recommendations') {
                $second = $secondary[$language] ?? '';
                $dirty = $dirty || $second !== ($saved[$language]?->secondary_body ?? '');
                $contents[$language]['secondary_body'] = $second;
            }
        }

        return [$this->store($document, $user, $contents), $dirty];
    }

    public function store(Document $document, User $user, array $contents): string
    {
        $token = Str::random(48);
        Cache::put('draft-preview:'.$token, [
            'user_id' => $user->id,
            'document_id' => $document->id,
            'company_id' => $document->company_id,
            'contents' => $contents,
        ], now()->addMinutes(30));

        return $token;
    }

    public function contents(?string $token, Document $document, User $user): ?array
    {
        if (! $token || ! preg_match('/^[A-Za-z0-9]{48}$/D', $token) || $document->is_locked) {
            return null;
        }
        $preview = Cache::get('draft-preview:'.$token);
        if (! is_array($preview) || (int) ($preview['user_id'] ?? 0) !== (int) $user->id
            || (int) ($preview['document_id'] ?? 0) !== (int) $document->id
            || (int) ($preview['company_id'] ?? 0) !== (int) $document->company_id) {
            return null;
        }

        return is_array($preview['contents'] ?? null) ? $preview['contents'] : null;
    }
}
