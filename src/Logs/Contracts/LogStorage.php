<?php

namespace Vigilance\Logs\Contracts;

use Illuminate\Support\Collection;
use Vigilance\Logs\LogEntry;

/**
 * Persists and reads back captured application logs. The default implementation
 * writes to the Vigilance database (optionally a dedicated connection) with a
 * single batched insert per flush.
 */
interface LogStorage
{
    /**
     * Persist a batch of log rows assembled by the collector.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function store(array $rows): void;

    /**
     * Most recent matching logs (newest first) for the explorer.
     *
     * @param  array<string, mixed>  $filters  e.g. ['min_level' => 300, 'channel' => 'single', 'q' => 'timeout', 'trace_id' => '…']
     * @return Collection<int, LogEntry>
     */
    public function search(array $filters = [], int $limit = 100): Collection;

    /**
     * Every log line correlated to a given trace, oldest first (timeline order).
     *
     * @return Collection<int, LogEntry>
     */
    public function forTrace(string $traceId, int $limit = 200): Collection;

    /**
     * Distinct channel names present in the table, for the filter dropdown.
     *
     * @return list<string>
     */
    public function channels(int $limit = 50): array;

    /**
     * Delete logs older than the retention window.
     */
    public function trim(): void;

    /**
     * Remove all captured logs.
     */
    public function purge(): void;
}
