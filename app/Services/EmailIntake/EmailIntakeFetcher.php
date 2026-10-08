<?php

namespace App\Services\EmailIntake;

use App\Support\HealthPing;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailIntakeFetcher
{
    public function run(IntakeMailbox $mailbox): array
    {
        $counts = ['accepted' => 0, 'rejected' => 0, 'failed' => 0, 'config_warnings' => 0];
        try {
            $validity = $mailbox->uidValidity();
            $name = implode(':', [config('displanery.email_intake.imap.host'), config('displanery.email_intake.imap.username'), config('displanery.email_intake.imap.folder')]);
            foreach ($mailbox->oldest(50) as $message) {
                if (! app(EmailSenderVerifier::class)->verdict($message->rawHeaders)['trusted']) {
                    $counts['config_warnings']++;
                }
                $outcome = app(EmailIntakeProcessor::class)->process($mailbox, $message, $name, $validity);
                if (isset($counts[$outcome])) {
                    $counts[$outcome]++;
                }
                if ($outcome === 'retry') {
                    $counts['failed']++;

                    continue;
                }
                try {
                    $mailbox->move($message->uid, config('displanery.email_intake.folders')[$outcome === 'accepted' ? 'processed' : 'discarded']);
                } catch (Throwable) {
                    $counts['failed']++;
                    Log::warning('Email intake move failed');
                }
            }
            $mailbox->purge(config('displanery.email_intake.folders.processed'), (int) config('displanery.email_intake.processed_days'));
            $mailbox->purge(config('displanery.email_intake.folders.discarded'), (int) config('displanery.email_intake.discarded_days'));
            if ($counts['failed'] || $counts['config_warnings']) {
                app(HealthPing::class)->failure('email_intake', 'Failed messages: '.$counts['failed'].'; untrusted headers: '.$counts['config_warnings']);
            } else {
                app(HealthPing::class)->success('email_intake');
            }
        } catch (Throwable) {
            $counts['failed']++;
            Log::warning('Email intake connection or read failed');
            app(HealthPing::class)->failure('email_intake', 'Connection or read failed; count: '.$counts['failed']);
        }

        return $counts;
    }
}
