<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects guests away from protected queue and party actions', function () {
    $this->post(route('queue.join'), [])
        ->assertRedirect(route('login'));

    $this->post(route('party.create'), [])
        ->assertRedirect(route('login'));

    $this->post(route('matches.accept'), [])
        ->assertRedirect(route('login'));
});

