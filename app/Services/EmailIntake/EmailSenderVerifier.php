<?php

namespace App\Services\EmailIntake;

use App\Enums\UserRole;
use App\Models\User;

class EmailSenderVerifier
{
    public function sender(string $rawHeaders): array
    {
        $headers = $this->headers($rawHeaders);
        $from = $headers['from'][0] ?? '';
        if (count($headers['from'] ?? []) !== 1
            || ! preg_match('/^(?:[^<]*<)?([A-Z0-9.!#$%&\'*+\/=^_`{|}~-]+@[A-Z0-9.-]+)(?:>)?\s*$/i', trim($from), $matches)) {
            return [null, 'auth_failed', ''];
        }
        $address = mb_strtolower($matches[1]);
        $domain = substr(strrchr($address, '@'), 1);
        if (! $this->authenticated($rawHeaders, $domain)) {
            return [null, 'auth_failed', $domain];
        }
        $users = User::query()->whereRaw('LOWER(email) = ?', [$address])->get();
        if ($users->isEmpty()) {
            return [null, 'unknown_sender', $domain];
        }
        $active = $users->first(fn (User $user) => $user->is_active);
        if (! $active) {
            return [null, 'inactive_sender', $domain];
        }
        if (! in_array($active->primary_role, [UserRole::Reporter, UserRole::DeptManager, UserRole::HrStaff, UserRole::CompanyAdmin], true)) {
            return [null, 'not_allowed_role', $domain];
        }
        if (! $active->company?->is_active) {
            return [null, 'inactive_sender', $domain];
        }

        return [$active, null, $domain];
    }

    public function verdict(string $rawHeaders): array
    {
        $first = $this->headers($rawHeaders)['authentication-results'][0] ?? '';
        $id = mb_strtolower(trim(explode(';', $first, 2)[0]));
        $trusted = mb_strtolower(trim((string) config('displanery.email_intake.trusted_authserv_id')));

        return ['authserv_id' => $id, 'trusted' => $id !== '' && $id === $trusted,
            'dmarc' => $this->result($first, 'dmarc'), 'spf' => $this->result($first, 'spf'),
            'dkim' => $this->result($first, 'dkim')];
    }

    public function authenticated(string $rawHeaders, string $fromDomain): bool
    {
        $first = $this->headers($rawHeaders)['authentication-results'][0] ?? '';
        if (! $this->verdict($rawHeaders)['trusted']) {
            return false;
        }
        if ($this->result($first, 'dmarc') === 'pass') {
            return true;
        }
        $spfDomain = $this->parameter($first, 'smtp.mailfrom');
        $dkimDomain = $this->parameter($first, 'header.d');

        return ($this->result($first, 'spf') === 'pass' && $this->aligned($spfDomain, $fromDomain))
            || ($this->result($first, 'dkim') === 'pass' && $this->aligned($dkimDomain, $fromDomain));
    }

    private function aligned(string $candidate, string $fromDomain): bool
    {
        $candidate = mb_strtolower(trim($candidate, '<> '));
        if (str_contains($candidate, '@')) {
            $candidate = substr(strrchr($candidate, '@'), 1);
        }

        return $candidate === $fromDomain || str_ends_with($candidate, '.'.$fromDomain);
    }

    private function result(string $value, string $method): string
    {
        return preg_match('/(?:^|[;\s])'.preg_quote($method, '/').'\s*=\s*([a-z]+)/i', $value, $matches)
            ? mb_strtolower($matches[1]) : '';
    }

    private function parameter(string $value, string $name): string
    {
        return preg_match('/(?:^|[;\s])'.preg_quote($name, '/').'\s*=\s*([^;\s]+)/i', $value, $matches)
            ? $matches[1] : '';
    }

    public function headers(string $rawHeaders): array
    {
        $result = [];
        $last = null;
        foreach (preg_split('/\r\n|\n|\r/', $rawHeaders) as $line) {
            if ($line === '') {
                break;
            }
            if (preg_match('/^[ \t]/', $line) && $last !== null) {
                $index = count($result[$last]) - 1;
                $result[$last][$index] .= ' '.trim($line);
            } elseif (preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/', $line, $matches)) {
                $last = mb_strtolower($matches[1]);
                $result[$last][] = $matches[2];
            } else {
                $last = null;
            }
        }

        return $result;
    }
}
