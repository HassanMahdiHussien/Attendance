<?php

use App\Mail\OperationsMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

test('operational mail remains English under an Arabic request locale', function (): void {
    app()->setLocale('ar');
    Mail::fake();
    Artisan::call('ops:test-mail', ['address' => 'operator@example.test']);
    Mail::assertSent(OperationsMessage::class, fn (OperationsMessage $mail): bool => $mail->locale === 'en' && $mail->subjectLine === 'Attendance mail test');
});
