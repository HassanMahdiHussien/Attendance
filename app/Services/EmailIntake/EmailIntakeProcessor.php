<?php

namespace App\Services\EmailIntake;

use App\Models\EmailDraft;
use App\Models\EmailIntakeMessage;
use App\Models\EmailIntakeRejection;
use App\Services\Attachments\AttachmentService;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EmailIntakeProcessor
{
    public function process(IntakeMailbox $mailbox, IntakeMessage $message, string $name, int $uidValidity): string
    {
        $identity = ['mailbox' => $name, 'uidvalidity' => $uidValidity, 'uid' => $message->uid];
        $record = EmailIntakeMessage::query()->where($identity)->first();
        if ($record && $record->outcome !== 'failed') {
            return $record->outcome;
        }
        if ($record && $record->attempts >= 3) {
            return 'failed';
        }
        $paths = [];
        try {
            if ($message->size > (int) config('displanery.email_intake.max_message_bytes')) {
                $domain = $this->senderDomain($message->rawHeaders);
                $this->reject($identity, $message, $domain, 'too_large');

                return 'rejected';
            }
            [$sender, $reason, $domain] = app(EmailSenderVerifier::class)->sender($message->rawHeaders);
            if ($reason !== null) {
                $this->reject($identity, $message, $domain, $reason);

                return 'rejected';
            }
            $message = $mailbox->hydrate($message);
            [$subject, $body, $truncated] = app(EmailContentSanitizer::class)->content($message);
            DB::transaction(function () use ($sender, $message, $subject, $body, $truncated, $identity, &$paths): void {
                $draftId = app(TenantContext::class)->runForCompany($sender->company_id, function () use ($sender, $message, $subject, $body, $truncated, &$paths): int {
                    $headers = app(EmailSenderVerifier::class)->headers($message->rawHeaders);
                    $messageId = trim($headers['message-id'][0] ?? '', "<> \t\r\n");
                    $hash = $messageId === '' ? null : hash('sha256', mb_strtolower($messageId));
                    $existing = $hash ? EmailDraft::query()->where('company_id', $sender->company_id)->where('message_id_hash', $hash)->first() : null;
                    if ($existing) {
                        return $existing->id;
                    }
                    $branches = DB::table('branch_user')->where('company_id', $sender->company_id)->where('user_id', $sender->id)->pluck('branch_id');
                    $draft = EmailDraft::create([
                        'company_id' => $sender->company_id, 'branch_id' => $branches->count() === 1 ? $branches->first() : null,
                        'sender_user_id' => $sender->id, 'message_id_hash' => $hash, 'subject' => $subject,
                        'body_text' => $body, 'body_truncated' => $truncated, 'received_at' => $message->receivedAt,
                        'excluded_attachments' => [],
                    ]);
                    $excluded = [];
                    $kept = 0;
                    foreach ($message->attachments as $part) {
                        $name = $this->safeName((string) ($part['name'] ?? 'attachment'));
                        $content = (string) ($part['content'] ?? '');
                        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
                        $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        $valid = in_array([$mime, $extension], [
                            ['application/pdf', 'pdf'], ['image/jpeg', 'jpg'], ['image/jpeg', 'jpeg'], ['image/png', 'png'],
                        ], true);
                        if ($kept >= 5 || strlen($content) > 10 * 1024 * 1024 || ! $valid) {
                            $excluded[] = $name;

                            continue;
                        }
                        $attachment = app(AttachmentService::class)->storeEmailBytes($draft, $name, $content, $sender);
                        $paths[] = $attachment->path;
                        $kept++;
                    }
                    if ($excluded) {
                        $draft->update(['excluded_attachments' => $excluded]);
                    }
                    app(AuditLogger::class)->log('email_draft.received', $draft, actor: null);

                    return $draft->id;
                });
                EmailIntakeMessage::query()->updateOrCreate($identity, ['outcome' => 'accepted', 'attempts' => 1, 'email_draft_id' => $draftId]);
            });

            return 'accepted';
        } catch (Throwable) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            $attempts = ($record?->attempts ?? 0) + 1;
            EmailIntakeMessage::query()->updateOrCreate($identity, ['outcome' => 'failed', 'attempts' => $attempts]);
            Log::warning('Email intake message failed', ['attempts' => $attempts]);

            return $attempts >= 3 ? 'failed' : 'retry';
        }
    }

    private function reject(array $identity, IntakeMessage $message, string $domain, string $reason): void
    {
        DB::transaction(function () use ($identity, $message, $domain, $reason): void {
            EmailIntakeRejection::create(['received_at' => $message->receivedAt, 'sender_domain' => $domain, 'reason' => $reason]);
            EmailIntakeMessage::query()->updateOrCreate($identity, ['outcome' => 'rejected', 'attempts' => 1]);
        });
    }

    private function senderDomain(string $headers): string
    {
        $from = app(EmailSenderVerifier::class)->headers($headers)['from'][0] ?? '';

        return preg_match('/@([a-z0-9.-]+)/i', $from, $matches) ? mb_strtolower($matches[1]) : '';
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return mb_substr(preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', mb_scrub($name, 'UTF-8')), 0, 255) ?: 'attachment';
    }
}
