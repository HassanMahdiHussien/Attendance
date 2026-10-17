<?php

test('a local proxy can mark the request as HTTPS so generated links use https', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'demo.example.test'])
        ->get('/login')->assertOk()->assertSee('https://demo.example.test', false);
});

test('a remote client cannot fake the scheme or its address through forwarded headers', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example.test', 'X-Forwarded-For' => '10.0.0.1'])
        ->get('/login')->assertOk()->assertDontSee('evil.example.test', false);
});
