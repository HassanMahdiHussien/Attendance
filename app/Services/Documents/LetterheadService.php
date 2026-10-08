<?php

namespace App\Services\Documents;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyLetterhead;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LetterheadService
{
    public function __construct(private AuditLogger $audit) {}

    public function saveVersion(Company $company, string $language, array $fields, User $actor, ?UploadedFile $logo = null, ?UploadedFile $headerImage = null): CompanyLetterhead
    {
        abort_unless(in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin], true)
            && ($actor->primary_role === UserRole::PlatformAdmin || (int) $actor->company_id === (int) $company->id), 403);
        if (! in_array($language, $company->enabled_languages, true)) {
            throw ValidationException::withMessages(['language' => 'اللغة غير مفعلة.']);
        }
        $fields = validator($fields, [
            'name' => 'required|string|max:255', 'address_lines' => 'nullable|string',
            'phone' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255',
            'footer_text' => 'required|string|max:255',
            'header_mode' => 'required|in:logo_text,image',
        ])->validate();
        $logoData = $this->image($logo, 'logo');
        $headerData = $this->image($headerImage, 'header_image');

        return DB::transaction(function () use ($company, $language, $fields, $actor, $logoData, $headerData): CompanyLetterhead {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $previous = CompanyLetterhead::query()->where('company_id', $company->id)
                ->where('language_code', $language)->orderByDesc('version')->first();
            foreach (['logo' => $logoData, 'header_image' => $headerData] as $key => $image) {
                if ($image) {
                    $path = "letterheads/{$company->id}/{$image['sha']}.{$image['ext']}";
                    $disk = Storage::disk('local');
                    if ($disk->exists($path) && hash('sha256', $disk->get($path)) !== $image['sha']) {
                        throw ValidationException::withMessages([$key => 'بصمة الصورة المخزنة غير مطابقة.']);
                    }
                    if (! $disk->exists($path) && ! $disk->put($path, $image['bytes'])) {
                        throw ValidationException::withMessages([$key => 'تعذر حفظ الصورة.']);
                    }
                    $fields[$key.'_path'] = $path;
                    $fields[$key.'_sha256'] = $image['sha'];
                } else {
                    $fields[$key.'_path'] = $previous?->{$key.'_path'};
                    $fields[$key.'_sha256'] = $previous?->{$key.'_sha256'};
                }
            }
            if ($fields['header_mode'] === 'image' && ! $fields['header_image_path']) {
                throw ValidationException::withMessages(['header_image' => 'تلزم صورة للترويسة في وضع الصورة.']);
            }
            $version = CompanyLetterhead::create($fields + [
                'company_id' => $company->id, 'language_code' => $language,
                'version' => ($previous?->version ?? 0) + 1, 'needs_review' => false,
                'created_by' => $actor->id, 'created_at' => now(),
            ]);
            $keys = ['version', 'name', 'address_lines', 'phone', 'email', 'footer_text', 'header_mode', 'logo_path', 'logo_sha256', 'header_image_path', 'header_image_sha256', 'needs_review'];
            $this->audit->log('company_letterhead.version_created', $version, [
                'before' => $previous?->only($keys), 'after' => $version->only($keys),
            ], $actor);

            return $version;
        });
    }

    private function image(?UploadedFile $file, string $field): ?array
    {
        if (! $file) {
            return null;
        }
        $bytes = file_get_contents($file->getRealPath());
        $mime = $bytes === false ? null : (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', default => null
        };
        if (! $ext || $file->getSize() > 2 * 1024 * 1024 || strlen($bytes) > 2 * 1024 * 1024 || getimagesizefromstring($bytes) === false) {
            throw ValidationException::withMessages([$field => 'ارفع صورة PNG أو JPG بحجم لا يتجاوز 2 MB.']);
        }

        return ['bytes' => $bytes, 'sha' => hash('sha256', $bytes), 'ext' => $ext];
    }
}
