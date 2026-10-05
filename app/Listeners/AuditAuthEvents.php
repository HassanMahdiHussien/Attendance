<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class AuditAuthEvents
{
    public function handle(Login|Logout|Failed $event): void
    {
        $action = match (true) {
            $event instanceof Login => 'auth.login',
            $event instanceof Logout => 'auth.logout',
            default => 'auth.failed',
        };
        $user = $event->user instanceof User ? $event->user : null;
        app(AuditLogger::class)->log($action, $user, [
            'email' => $event instanceof Failed ? mb_strtolower(trim((string) ($event->credentials['email'] ?? ''))) : $user?->email,
            'ip' => request()->ip(),
            'guard' => $event->guard,
            'panel' => Filament::getCurrentPanel()?->getId(),
            'user_agent' => request()->userAgent(),
        ], $user);
    }
}
