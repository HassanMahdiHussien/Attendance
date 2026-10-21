<?php

namespace App\Ai;

interface ClaudeClient
{
    public function structured(string $touchpoint, array $schema, array $messages, array $logContext, string $model, string $effort, array $options = []): array;
}
