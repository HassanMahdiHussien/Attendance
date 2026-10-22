<?php

namespace App\Console\Commands;

use App\Mail\OperationsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMail extends Command
{
    protected $signature = 'ops:test-mail {address}';

    protected $description = 'Send a plain operational mail test';

    public function handle(): int
    {
        $address = (string) $this->argument('address');
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }
        Mail::to($address)->send(new OperationsMessage('Attendance mail test', 'Attendance operational mail test.'));
        $this->info('Test mail sent.');

        return self::SUCCESS;
    }
}
