<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Models\WorkerRecord;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Supervision\SupervisorOptions;
use Vigilance\Supervision\SupervisorState;

uses(RefreshDatabase::class);

it('drives the control plane through cache flags', function () {
    $control = new ControlPlane;
    $control->reset();

    expect($control->status())->toBe(ControlPlane::RUNNING)
        ->and($control->isPaused())->toBeFalse();

    $control->pause();
    expect($control->isPaused())->toBeTrue();

    $control->continue();
    expect($control->status())->toBe(ControlPlane::RUNNING);

    $control->terminate();
    expect($control->isTerminating())->toBeTrue();

    expect($control->restartToken())->toBeNull();
    $control->restart();
    expect($control->restartToken())->not->toBeNull();
});

it('writes and reads supervisor + worker heartbeats', function () {
    $state = new SupervisorState;
    $options = SupervisorOptions::fromArray([
        'name' => 'supervisor-1',
        'connection' => 'database',
        'queue' => ['default'],
        'balance' => 'auto',
    ]);

    $state->heartbeat($options, 'running', ['default' => 2], [
        ['pid' => 111, 'queue' => 'default'],
        ['pid' => 112, 'queue' => 'default'],
    ]);

    $supervisor = SupervisorRecord::query()->where('name', 'supervisor-1')->first();
    expect($supervisor)->not->toBeNull()
        ->and($supervisor->processes)->toBe(2)
        ->and($supervisor->status)->toBe('running')
        ->and($supervisor->pools)->toBe(['default' => 2]);

    expect(WorkerRecord::query()->where('supervisor', 'supervisor-1')->count())->toBe(2);

    expect($state->active(30))->toHaveCount(1);

    // A re-heartbeat replaces the worker set rather than duplicating it.
    $state->heartbeat($options, 'running', ['default' => 1], [['pid' => 113, 'queue' => 'default']]);
    expect(WorkerRecord::query()->where('supervisor', 'supervisor-1')->count())->toBe(1);
});

it('prunes supervisors that stopped heartbeating', function () {
    $state = new SupervisorState;
    $options = SupervisorOptions::fromArray(['name' => 'supervisor-1', 'connection' => 'database']);

    $state->heartbeat($options, 'running', ['default' => 1], [['pid' => 1, 'queue' => 'default']]);

    SupervisorRecord::query()->where('name', 'supervisor-1')->update(['last_heartbeat_at' => Carbon::now()->subMinutes(5)]);

    expect($state->active(30))->toHaveCount(0);

    $state->pruneExpired(30);

    expect(SupervisorRecord::query()->count())->toBe(0)
        ->and(WorkerRecord::query()->count())->toBe(0);
});

it('keeps a separate heartbeat row per node for the same supervisor name', function () {
    $options = SupervisorOptions::fromArray(['name' => 'supervisor-1', 'connection' => 'database']);

    // Two nodes, same supervisor name — simulate distinct hosts via the config
    // identity the state layer reads.
    config()->set('vigilance.supervision.host', 'node-A');
    (new SupervisorState)->heartbeat($options, 'running', ['default' => 3], [
        ['pid' => 11, 'queue' => 'default'], ['pid' => 12, 'queue' => 'default'], ['pid' => 13, 'queue' => 'default'],
    ]);

    config()->set('vigilance.supervision.host', 'node-B');
    (new SupervisorState)->heartbeat($options, 'running', ['default' => 5], [
        ['pid' => 21, 'queue' => 'default'], ['pid' => 22, 'queue' => 'default'],
        ['pid' => 23, 'queue' => 'default'], ['pid' => 24, 'queue' => 'default'], ['pid' => 25, 'queue' => 'default'],
    ]);

    // Both nodes survive — no clobber — and the fleet totals are correct.
    expect(SupervisorRecord::query()->where('name', 'supervisor-1')->count())->toBe(2)
        ->and((int) SupervisorRecord::query()->sum('processes'))->toBe(8)
        ->and(WorkerRecord::query()->where('supervisor', 'supervisor-1')->count())->toBe(8);

    // node-A re-heartbeats: only its own 3 worker rows are replaced; node-B's 5 stay.
    config()->set('vigilance.supervision.host', 'node-A');
    (new SupervisorState)->heartbeat($options, 'running', ['default' => 1], [['pid' => 14, 'queue' => 'default']]);

    expect(WorkerRecord::query()->where('host', 'node-A')->count())->toBe(1)
        ->and(WorkerRecord::query()->where('host', 'node-B')->count())->toBe(5);

    // Forgetting node-A leaves node-B intact.
    (new SupervisorState)->forget('supervisor-1', 'node-A');
    expect(SupervisorRecord::query()->where('name', 'supervisor-1')->count())->toBe(1)
        ->and(SupervisorRecord::query()->where('name', 'supervisor-1')->first()->host)->toBe('node-B');
});
