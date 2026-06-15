<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Http\Livewire\Logs;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Logs\LogLevel;

uses(RefreshDatabase::class);

it('renders captured logs on the explorer', function () {
    config()->set('vigilance.logs.enabled', true);

    app(LogStorage::class)->store([[
        'level' => 'error',
        'level_value' => LogLevel::value('error'),
        'message' => 'queue connection refused',
        'context' => null,
        'channel' => 'single',
        'trace_id' => null,
        'logged_at' => now()->getTimestamp(),
        'created_at' => now(),
    ]]);

    Livewire::test(Logs::class)
        ->assertSee('queue connection refused')
        ->assertSee('error');
});

it('filters the explorer by minimum level', function () {
    config()->set('vigilance.logs.enabled', true);

    app(LogStorage::class)->store([
        [
            'level' => 'info', 'level_value' => LogLevel::value('info'), 'message' => 'routine info line',
            'context' => null, 'channel' => 'single', 'trace_id' => null,
            'logged_at' => now()->getTimestamp(), 'created_at' => now(),
        ],
        [
            'level' => 'error', 'level_value' => LogLevel::value('error'), 'message' => 'critical failure line',
            'context' => null, 'channel' => 'single', 'trace_id' => null,
            'logged_at' => now()->getTimestamp(), 'created_at' => now(),
        ],
    ]);

    Livewire::test(Logs::class)
        ->set('level', 'error')
        ->assertSee('critical failure line')
        ->assertDontSee('routine info line');
});

it('shows a disabled hint when log capture is off', function () {
    config()->set('vigilance.logs.enabled', false);

    Livewire::test(Logs::class)
        ->assertSee('Log capture is off')
        ->assertSee('No logs match');
});
