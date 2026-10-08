<?php

use App\Models\User;

test('guests can enter through the English home page', function (): void {
    $this->get('/')->assertOk()->assertSee('Displanery')->assertSee('Log in')
        ->assertSee(route('login'));
});

test('signed-in users go directly to their workspace', function (): void {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('workspace.home'));
});
