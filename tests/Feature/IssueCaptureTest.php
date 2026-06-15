<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Capture\IssueCapture;
use Vigilance\Models\FailureGroup;

uses(RefreshDatabase::class);

it('captures a reported exception into a grouped issue with a sample', function () {
    $capture = app(IssueCapture::class);

    $capture->capture(new RuntimeException('boom'), 'reported');
    $capture->capture(new RuntimeException('boom'), 'reported');

    $group = FailureGroup::query()->first();

    expect($group)->not->toBeNull()
        ->and($group->occurrences)->toBe(2)
        ->and($group->source)->toBe('reported')
        ->and($group->exception_class)->toBe(RuntimeException::class)
        ->and($group->sample)->toContain('boom')
        ->and($group->context['file'] ?? null)->toContain(basename(__FILE__));
});

it('reopens a resolved issue when it recurs', function () {
    $capture = app(IssueCapture::class);

    $capture->capture(new RuntimeException('x'), 'reported');
    $group = FailureGroup::query()->first();
    $group->update(['resolved_at' => now()]);

    $capture->capture(new RuntimeException('x'), 'reported');

    expect($group->fresh()->resolved_at)->toBeNull();
});

it('respects the issues.except ignore list', function () {
    config()->set('vigilance.issues.except', [RuntimeException::class]);

    app(IssueCapture::class)->capture(new RuntimeException('nope'), 'reported');

    expect(FailureGroup::query()->count())->toBe(0);
});

it('does nothing when issue capture is disabled', function () {
    config()->set('vigilance.issues.enabled', false);

    app(IssueCapture::class)->capture(new RuntimeException('off'), 'reported');

    expect(FailureGroup::query()->count())->toBe(0);
});

it('reports muted status from the model', function () {
    $group = new FailureGroup;
    $group->signature = 'sig-muted';
    $group->muted_until = now()->addHour();

    expect($group->isMuted())->toBeTrue()
        ->and($group->status())->toBe('muted');

    $group->muted_until = now()->subHour();

    expect($group->isMuted())->toBeFalse()
        ->and($group->status())->toBe('open');
});
