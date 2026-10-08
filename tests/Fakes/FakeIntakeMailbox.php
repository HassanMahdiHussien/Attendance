<?php

namespace Tests\Fakes;

use App\Services\EmailIntake\IntakeMailbox;
use App\Services\EmailIntake\IntakeMessage;
use RuntimeException;

class FakeIntakeMailbox implements IntakeMailbox
{
    public array $messages = [];

    public array $moved = [];

    public array $purged = [];

    public bool $failConnect = false;

    public bool $failMove = false;

    public bool $failHydrate = false;

    public function uidValidity(): int
    {
        if ($this->failConnect) {
            throw new RuntimeException('Private mailbox connection detail');
        }

        return 42;
    }

    public function oldest(int $limit): array
    {
        return array_slice(array_values($this->messages), 0, $limit);
    }

    public function latest(): ?IntakeMessage
    {
        return collect($this->messages)->sortKeysDesc()->first();
    }

    public function hydrate(IntakeMessage $message): IntakeMessage
    {
        if ($this->failHydrate) {
            throw new RuntimeException('Private malformed content');
        }

        return $message;
    }

    public function move(int $uid, string $folder): void
    {
        if ($this->failMove) {
            throw new RuntimeException('Private move detail');
        }
        $this->moved[$uid] = $folder;
        unset($this->messages[$uid]);
    }

    public function purge(string $folder, int $days): void
    {
        $this->purged[$folder] = $days;
    }
}
