<?php

namespace App\Services\Attendance\Ai;

use RuntimeException;
use Throwable;

class FakeChatModel implements ChatModel
{
    public array $calls = [];

    public function __construct(private array $script = []) {}

    public function available(): bool
    {
        return true;
    }

    public function complete(array $messages, array $tools, string $system): array
    {
        $this->calls[] = compact('messages', 'tools', 'system');
        if ($this->script === []) {
            throw new RuntimeException('No scripted model response remains.');
        }
        $next = array_shift($this->script);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next + ['model' => 'fake-chat', 'input_tokens' => 0, 'output_tokens' => 0];
    }
}
