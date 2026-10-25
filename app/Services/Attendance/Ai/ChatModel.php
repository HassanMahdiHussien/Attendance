<?php

namespace App\Services\Attendance\Ai;

interface ChatModel
{
    public function complete(array $messages, array $tools, string $system): array;
}
