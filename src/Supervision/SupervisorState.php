<?php

namespace Vigilance\Supervision;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Models\WorkerRecord;

/**
 * Persists the live state of supervisors and their workers (a heartbeat with
 * expiry, like Horizon's Redis repos but in DB tables so it's driver-agnostic).
 * The dashboard and `vigilance:status` read from here.
 */
class SupervisorState
{
    /**
     * Write/refresh a supervisor's heartbeat and the set of its current workers.
     *
     * @param  array<string, int>  $pools  process count per pool key
     * @param  list<array{pid: int, queue: string}>  $workers
     */
    public function heartbeat(SupervisorOptions $options, string $status, array $pools, array $workers): void
    {
        $now = Carbon::now();
        $host = static::host();

        // Keyed by (name, host): the same supervisor running on several nodes
        // keeps a row per node, so the dashboard sees the whole fleet instead of
        // nodes overwriting each other's heartbeat and worker lists.
        SupervisorRecord::query()->updateOrCreate(
            ['name' => $options->name, 'host' => $host],
            [
                'pid' => getmypid() ?: null,
                'status' => $status,
                'connection' => $options->connection,
                'queues' => implode(',', $options->queue),
                'balance' => $options->balance,
                'processes' => array_sum($pools),
                'pools' => $pools,
                'options' => $options->toArray(),
                'last_heartbeat_at' => $now,
            ],
        );

        // Only THIS node's workers — never another node's rows for the same name.
        WorkerRecord::query()
            ->where('supervisor', $options->name)
            ->where('host', $host)
            ->delete();

        if ($workers !== []) {
            WorkerRecord::query()->insert(array_map(fn (array $w) => [
                'supervisor' => $options->name,
                'host' => $host,
                'pid' => $w['pid'],
                'connection' => $options->connection,
                'queue' => $w['queue'],
                'status' => $status,
                'last_heartbeat_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $workers));
        }
    }

    public function forget(string $name, ?string $host = null): void
    {
        $host ??= static::host();

        SupervisorRecord::query()->where('name', $name)->where('host', $host)->delete();
        WorkerRecord::query()->where('supervisor', $name)->where('host', $host)->delete();
    }

    /**
     * Remove supervisors (and their workers) that stopped heartbeating. Operates
     * per (name, host) row, so a dead node is pruned without touching live nodes
     * that share its supervisor name.
     */
    public function pruneExpired(int $seconds): void
    {
        $cutoff = Carbon::now()->subSeconds(max(1, $seconds));

        $stale = SupervisorRecord::query()
            ->where('last_heartbeat_at', '<', $cutoff)
            ->get(['name', 'host']);

        foreach ($stale as $row) {
            WorkerRecord::query()
                ->where('supervisor', $row->name)
                ->where('host', $row->host)
                ->delete();
        }

        SupervisorRecord::query()
            ->where('last_heartbeat_at', '<', $cutoff)
            ->delete();
    }

    /**
     * This node's identity for supervisor/worker rows. Configurable so distinct
     * nodes (especially containers, where gethostname() may be random or shared)
     * can be told apart; falls back to the hostname.
     */
    public static function host(): string
    {
        return (string) (config('vigilance.supervision.host') ?: (gethostname() ?: 'localhost'));
    }

    /**
     * Supervisors considered alive (heartbeat within the expiry window).
     *
     * @return Collection<int, SupervisorRecord>
     */
    public function active(int $seconds): Collection
    {
        return SupervisorRecord::query()
            ->where('last_heartbeat_at', '>=', Carbon::now()->subSeconds(max(1, $seconds)))
            ->orderBy('name')
            ->get();
    }
}
