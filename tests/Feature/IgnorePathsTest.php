<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Http\Controllers\RumController;
use Vigilance\Support\PathMatcher;

uses(RefreshDatabase::class);

it('matches wildcard globs and delimited regexes', function () {
    expect(PathMatcher::matchesAny('/admin/users', ['/admin/*']))->toBeTrue()
        ->and(PathMatcher::matchesAny('/admin', ['/admin/*']))->toBeFalse()
        // Patterns are forgiving about a leading slash.
        ->and(PathMatcher::matchesAny('/livewire/update', ['livewire/*']))->toBeTrue()
        ->and(PathMatcher::matchesAny('/internal/metrics', ['#^/internal#']))->toBeTrue()
        ->and(PathMatcher::matchesAny('/shop/cart', ['/admin/*', '#^/internal#']))->toBeFalse()
        ->and(PathMatcher::matchesAny('/x', []))->toBeFalse();
});

it('reads the global ignore_paths config', function () {
    config()->set('vigilance.ignore_paths', ['/admin/*', 'horizon*']);

    expect(PathMatcher::ignored('/admin/dashboard'))->toBeTrue()
        ->and(PathMatcher::ignored('/horizon/api'))->toBeTrue()
        ->and(PathMatcher::ignored('/shop'))->toBeFalse();
});

it('drops RUM beacons for globally-ignored pages', function () {
    config()->set('vigilance.rum.enabled', true);
    config()->set('vigilance.ignore_paths', ['/admin/*']);

    $post = function (string $page): void {
        $request = Request::create('/vigilance/rum', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'page' => $page,
            'metrics' => [['name' => 'lcp', 'value' => 1200]],
        ]));

        app(RumController::class)->store($request, app(Apm::class), app(FailureGrouper::class));
    };

    $post('/admin/dashboard'); // ignored
    $post('/shop');            // recorded
    app(Apm::class)->ingest();

    $keys = app(Storage::class)->aggregate('web_vital', ['count'], CarbonInterval::hour())->pluck('key')->implode('|');

    expect($keys)->toContain('/shop')
        ->and($keys)->not->toContain('/admin');
});
