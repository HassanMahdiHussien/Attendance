<?php

namespace App\Services\EmailIntake;

interface IntakeMailbox
{
    public function uidValidity(): int;

    /** @return array<IntakeMessage> */
    public function oldest(int $limit): array;

    public function latest(): ?IntakeMessage;

    public function hydrate(IntakeMessage $message): IntakeMessage;

    public function move(int $uid, string $folder): void;

    public function purge(string $folder, int $days): void;
}
