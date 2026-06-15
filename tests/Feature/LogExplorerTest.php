<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Logs\LogCollector;
use Vigilance\Logs\LogLevel;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function logRow(array $overrides = []): array
{
    return array_merge([
        'level' => 'info',
        'level_value' => 200,
        'message' => 'a log line',
        'context' => null,
        'channel' => 'single',
        'trace_id' => null,
        'logged_at' => now()->getTimestamp(),
        'created_at' => now(),
    ], $overrides);
}

it('drops records below the configured minimum level', function () {
    config()->set('vigilance.logs.enabled', true);
    config()->set('vigilance.logs.level', 'warning');

    $collector = new LogCollector(app());
    $collector->record(new MessageLogged('info', 'just chatter'));
    $collector->record(new MessageLogged('error', 'something broke'));
    $collector->flush();

    $logs = app(LogStorage::class)->search();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->level)->toBe('error');
});

it('skips messages matching an ignore pattern', function () {
    config()->set('vigilance.logs.enabled', true);
    config()->set('vigilance.logs.level', 'debug');
    config()->set('vigilance.logs.ignore', ['#health-check#']);

    $collector = new LogCollector(app());
    $collector->record(new MessageLogged('info', 'health-check ok'));
    $collector->record(new MessageLogged('info', 'real event'));
    $collector->flush();

    $logs = app(LogStorage::class)->search();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->message)->toBe('real event');
});

it('redacts secrets from captured context', function () {
    config()->set('vigilance.logs.enabled', true);
    config()->set('vigilance.logs.level', 'debug');

    $collector = new LogCollector(app());
    $collector->record(new MessageLogged('info', 'login', ['user' => 'jane', 'password' => 'hunter2']));
    $collector->flush();

    $context = app(LogStorage::class)->search()->first()->context;

    expect($context['user'])->toBe('jane')
        ->and($context['password'])->toBe('[redacted]');
});

it('searches by minimum level, channel and message', function () {
    app(LogStorage::class)->store([
        logRow(['level' => 'info', 'level_value' => LogLevel::value('info'), 'channel' => 'single', 'message' => 'cache warmed']),
        logRow(['level' => 'error', 'level_value' => LogLevel::value('error'), 'channel' => 'slack', 'message' => 'db timeout']),
        logRow(['level' => 'warning', 'level_value' => LogLevel::value('warning'), 'channel' => 'single', 'message' => 'slow query']),
    ]);

    $storage = app(LogStorage::class);

    expect($storage->search(['min_level' => LogLevel::value('warning')]))->toHaveCount(2)
        ->and($storage->search(['channel' => 'slack']))->toHaveCount(1)
        ->and($storage->search(['q' => 'timeout']))->toHaveCount(1)
        ->and($storage->search(['q' => 'timeout'])->first()->message)->toBe('db timeout');
});

it('lists distinct channels for the filter', function () {
    app(LogStorage::class)->store([
        logRow(['channel' => 'single']),
        logRow(['channel' => 'slack']),
        logRow(['channel' => 'single']),
    ]);

    expect(app(LogStorage::class)->channels())->toEqualCanonicalizing(['single', 'slack']);
});

it('trims logs older than the retention window', function () {
    config()->set('vigilance.logs.retention', '72 hours');

    app(LogStorage::class)->store([
        logRow(['message' => 'ancient', 'logged_at' => now()->subDays(10)->getTimestamp()]),
        logRow(['message' => 'fresh', 'logged_at' => now()->getTimestamp()]),
    ]);

    app(LogStorage::class)->trim();

    $logs = app(LogStorage::class)->search();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->message)->toBe('fresh');
});
