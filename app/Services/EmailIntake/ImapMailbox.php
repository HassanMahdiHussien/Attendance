<?php

namespace App\Services\EmailIntake;

use Carbon\CarbonImmutable;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class ImapMailbox implements IntakeMailbox
{
    private ?Client $client = null;

    private function client(): Client
    {
        if ($this->client === null) {
            $settings = config('displanery.email_intake.imap');
            // The mailbox password and report contents must never cross the network in clear text.
            if (app()->isProduction() && ! in_array($settings['encryption'], ['ssl', 'tls', 'starttls'], true)) {
                throw new RuntimeException('Email intake requires an encrypted IMAP connection in production.');
            }
            $this->client = (new ClientManager)->make([
                'host' => $settings['host'], 'port' => (int) $settings['port'],
                'encryption' => $settings['encryption'], 'validate_cert' => true,
                'username' => $settings['username'], 'password' => $settings['password'],
                'protocol' => 'imap', 'timeout' => 30,
            ]);
            $this->client->connect();
        }

        return $this->client;
    }

    private function folder(string $name): Folder
    {
        return $this->client()->getFolderByPath($name, false, true)
            ?? throw new RuntimeException('Mailbox folder unavailable');
    }

    public function uidValidity(): int
    {
        $status = $this->folder(config('displanery.email_intake.imap.folder'))->status();

        return (int) ($status['uidvalidity'] ?? throw new RuntimeException('Mailbox UID validity unavailable'));
    }

    public function oldest(int $limit): array
    {
        $messages = $this->folder(config('displanery.email_intake.imap.folder'))
            ->query(['INTERNALDATE'])->all()->leaveUnread()->setFetchBody(false)
            ->setFetchOrderAsc()->limit($limit)->get();

        return $messages->map(fn (Message $mail) => $this->summary($mail))->values()->all();
    }

    public function latest(): ?IntakeMessage
    {
        $mail = $this->folder(config('displanery.email_intake.imap.folder'))
            ->query(['INTERNALDATE'])->all()->leaveUnread()->setFetchBody(false)
            ->setFetchOrderDesc()->limit(1)->get()->first();

        return $mail ? $this->summary($mail) : null;
    }

    private function summary(Message $mail): IntakeMessage
    {
        // webklex stores fetch extensions under the upper-case IMAP item name, which Header::get()
        // (it lower-cases) can never find, so read the attribute map directly.
        $attributes = $mail->getHeader()?->getAttributes() ?? [];
        $internalDate = ($attributes['INTERNALDATE'] ?? $attributes['internaldate'] ?? null)?->first();
        if (! is_string($internalDate) || trim($internalDate) === '') {
            throw new RuntimeException('Mailbox internal date unavailable');
        }

        return new IntakeMessage(
            (int) $mail->uid, (int) $mail->size, (string) $mail->getHeader()?->raw,
            // The server reports its own offset; stored datetimes are in the app timezone.
            CarbonImmutable::parse($internalDate)->setTimezone(config('app.timezone')),
        );
    }

    public function hydrate(IntakeMessage $message): IntakeMessage
    {
        $mail = $this->folder(config('displanery.email_intake.imap.folder'))
            ->query()->where('UID', (string) $message->uid)->leaveUnread()->get()->first();
        if (! $mail) {
            throw new RuntimeException('Mailbox message unavailable');
        }
        $message->plainText = $mail->getTextBody();
        $message->html = $mail->getHTMLBody();
        $message->attachments = $mail->getAttachments()->map(fn ($part) => [
            'name' => (string) ($part->name ?: $part->filename),
            'content' => $part->getContent(),
        ])->all();

        return $message;
    }

    public function move(int $uid, string $folder): void
    {
        if (! $this->client()->getFolderByPath($folder, false, true)) {
            $this->client()->createFolder($folder);
        }
        $mail = $this->folder(config('displanery.email_intake.imap.folder'))
            ->query()->where('UID', (string) $uid)->leaveUnread()->setFetchBody(false)->get()->first();
        if ($mail && ! $mail->move($folder)) {
            throw new RuntimeException('Mailbox move failed');
        }
    }

    public function purge(string $folder, int $days): void
    {
        $target = $this->client()->getFolderByPath($folder, false, true);
        if (! $target) {
            return;
        }
        foreach ($target->query()->before(now()->subDays($days)->toDateString())
            ->leaveUnread()->setFetchBody(false)->get() as $mail) {
            $mail->delete();
        }
    }
}
