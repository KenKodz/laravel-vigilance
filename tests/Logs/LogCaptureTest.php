<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Logs\LogCollector;
use Vigilance\Tracing\Contracts\TraceStorage;

uses(RefreshDatabase::class);

it('captures a logged record into the explorer', function () {
    Route::get('/_log_probe', function () {
        Log::warning('disk usage high', ['disk' => '/dev/sda1']);

        return 'ok';
    });

    $this->get('/_log_probe')->assertOk();

    // The terminate hook flushes automatically; calling again is a safe no-op.
    app(LogCollector::class)->flush();

    $logs = app(LogStorage::class)->search();

    expect($logs)->toHaveCount(1);

    $log = $logs->first();
    expect($log->message)->toBe('disk usage high')
        ->and($log->level)->toBe('warning')
        ->and($log->context)->toHaveKey('disk');
});

it('correlates a log to the trace that emitted it', function () {
    Route::get('/_log_trace', function () {
        Log::error('payment gateway timeout');

        return 'ok';
    });

    $this->get('/_log_trace')->assertOk();
    app(LogCollector::class)->flush();

    $trace = app(TraceStorage::class)->recent(['type' => 'request'])
        ->firstWhere('name', 'GET /_log_trace');

    expect($trace)->not->toBeNull();

    $log = app(LogStorage::class)->search()->first();
    expect($log->traceId)->toBe($trace->id);

    $correlated = app(LogStorage::class)->forTrace($trace->id);
    expect($correlated)->toHaveCount(1)
        ->and($correlated->first()->message)->toBe('payment gateway timeout');
});
