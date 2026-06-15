<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\AlertManager;
use Vigilance\Notifications\Contracts\AlertRule;

uses(RefreshDatabase::class);

class SustainedTestRule implements AlertRule
{
    public function evaluate(): iterable
    {
        yield new Alert('sustained-test', 'Sustained', 'still breaching', (string) config('test.level', 'warning'));
    }
}

beforeEach(fn () => Http::fake());

it('notifies once for a sustained condition instead of every window', function () {
    config()->set('vigilance.alerts.custom', [SustainedTestRule::class]);

    // Opens the incident → one notification, then quiet on every later cycle.
    expect(app(AlertManager::class)->check())->toBe(1)
        ->and(app(AlertManager::class)->check())->toBe(0)
        ->and(app(AlertManager::class)->check())->toBe(0);
});

it('re-notifies an ongoing incident after the renotify window', function () {
    config()->set('vigilance.alerts.custom', [SustainedTestRule::class]);
    config()->set('vigilance.alerts.renotify_minutes', 30);

    expect(app(AlertManager::class)->check())->toBe(1) // opened + reminder armed
        ->and(app(AlertManager::class)->check())->toBe(0); // within the window

    $this->travel(31)->minutes();

    expect(app(AlertManager::class)->check())->toBe(1); // reminder due
});

it('re-notifies immediately when severity escalates', function () {
    config()->set('vigilance.alerts.custom', [SustainedTestRule::class]);
    config()->set('test.level', 'warning');

    expect(app(AlertManager::class)->check())->toBe(1)  // opened (warning)
        ->and(app(AlertManager::class)->check())->toBe(0); // ongoing

    config()->set('test.level', 'critical');

    expect(app(AlertManager::class)->check())->toBe(1)  // escalated → notify
        ->and(app(AlertManager::class)->check())->toBe(0); // ongoing (critical)
});

it('falls back to per-window throttling when incidents are disabled', function () {
    config()->set('vigilance.alerts.custom', [SustainedTestRule::class]);
    config()->set('vigilance.alerts.incidents', false);

    expect(app(AlertManager::class)->check())->toBe(1)
        ->and(app(AlertManager::class)->check())->toBe(0); // throttled in-window
});
