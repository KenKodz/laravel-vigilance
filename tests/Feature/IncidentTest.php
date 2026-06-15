<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Vigilance\Models\Incident;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\AlertManager;
use Vigilance\Notifications\Contracts\AlertRule;

uses(RefreshDatabase::class);

class IncidentTestRule implements AlertRule
{
    public function evaluate(): iterable
    {
        yield new Alert('incident-test', 'Test alert', 'Something happened', 'critical');
    }
}

beforeEach(fn () => Http::fake());

it('opens an incident when an alert fires and increments on recurrence', function () {
    config()->set('vigilance.alerts.custom', [IncidentTestRule::class]);

    app(AlertManager::class)->check();

    $incident = Incident::query()->where('key', 'incident-test')->first();

    expect($incident)->not->toBeNull()
        ->and($incident->status)->toBe('open')
        ->and($incident->occurrences)->toBe(1);

    // The condition still holds on the next cycle — same open incident, +1.
    app(AlertManager::class)->check();

    expect($incident->fresh()->occurrences)->toBe(2)
        ->and($incident->fresh()->status)->toBe('open');
});

it('auto-resolves an incident once its alert stops recurring', function () {
    config()->set('vigilance.alerts.custom', []);
    config()->set('vigilance.alerts.throttle_minutes', 15);

    Incident::query()->create([
        'key' => 'stale',
        'title' => 'Old incident',
        'message' => '',
        'level' => 'warning',
        'status' => 'open',
        'occurrences' => 1,
        'opened_at' => now()->subHours(2),
        'last_seen_at' => now()->subHour(), // older than 3 × 15min
    ]);

    app(AlertManager::class)->check();

    $incident = Incident::query()->where('key', 'stale')->first();

    expect($incident->status)->toBe('resolved')
        ->and($incident->resolved_at)->not->toBeNull()
        ->and($incident->durationSeconds())->toBeGreaterThan(3600);
});
