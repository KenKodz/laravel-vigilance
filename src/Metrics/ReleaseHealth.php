<?php

namespace Vigilance\Metrics;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Vigilance\Models\Deployment;

/**
 * Release health: for a deployment, compare the request telemetry in the window
 * after it shipped against the equal window before, to decide whether the deploy
 * made things worse (error rate up, latency up). This is what turns deploy
 * markers into a guard — "is the release we just shipped healthy?" — read on
 * demand from the raw APM request entries (never on the request hot path).
 *
 * @phpstan-type WindowStats array{requests: int, errors: int, latency: ?int, seconds: int}
 */
class ReleaseHealth
{
    /**
     * Health for the most recent deployments (newest first), for the dashboard.
     *
     * @return Collection<int, ReleaseHealthStatus>
     */
    public function recent(int $limit = 20): Collection
    {
        return Deployment::query()
            ->orderByDesc('deployed_at')
            ->limit($limit)
            ->get()
            ->map(fn (Deployment $d) => $this->forDeployment($d));
    }

    /**
     * Health for the latest deployment, or null if none recorded.
     */
    public function latest(): ?ReleaseHealthStatus
    {
        $deployment = Deployment::query()->orderByDesc('deployed_at')->first();

        return $deployment !== null ? $this->forDeployment($deployment) : null;
    }

    public function forDeployment(Deployment $deployment): ReleaseHealthStatus
    {
        $windowSeconds = max(60, (int) config('vigilance.release_health.window_minutes', 30) * 60);
        $minRequests = max(1, (int) config('vigilance.release_health.min_requests', 50));
        $errorRateIncrease = (float) config('vigilance.release_health.error_rate_increase', 5.0);
        $latencyIncrease = (float) config('vigilance.release_health.latency_increase', 0.5);

        $deployedAt = $deployment->deployed_at->getTimestamp();
        $now = now()->getTimestamp();

        $before = $this->windowStats(max(0, $deployedAt - $windowSeconds), $deployedAt);
        $after = $this->windowStats($deployedAt, min($deployedAt + $windowSeconds, $now));

        $errorRateBefore = $this->rate($before['errors'], $before['requests']);
        $errorRateAfter = $this->rate($after['errors'], $after['requests']);

        $verdict = $this->verdict(
            $before,
            $after,
            $minRequests,
            $errorRateBefore,
            $errorRateAfter,
            $errorRateIncrease,
            $latencyIncrease,
        );

        return new ReleaseHealthStatus(
            deploymentId: (int) $deployment->id,
            label: $deployment->label(),
            version: $deployment->version,
            commit: $deployment->shortCommit(),
            deployedAt: $deployedAt,
            verdict: $verdict,
            requestsBefore: $before['requests'],
            requestsAfter: $after['requests'],
            errorRateBefore: $errorRateBefore,
            errorRateAfter: $errorRateAfter,
            latencyBefore: $before['latency'],
            latencyAfter: $after['latency'],
            throughputDelta: $this->throughputDelta($before, $after),
        );
    }

    /**
     * @param  WindowStats  $before
     * @param  WindowStats  $after
     */
    protected function verdict(
        array $before,
        array $after,
        int $minRequests,
        float $errorRateBefore,
        float $errorRateAfter,
        float $errorRateIncrease,
        float $latencyIncrease,
    ): string {
        if ($before['requests'] < $minRequests || $after['requests'] < $minRequests) {
            return 'no-data';
        }

        $errorJump = $errorRateAfter - $errorRateBefore;

        $latencyJump = 0.0;
        if ($before['latency'] !== null && $after['latency'] !== null && $before['latency'] > 0) {
            $latencyJump = ($after['latency'] - $before['latency']) / $before['latency'];
        }

        // Regressed: a clear error-rate or latency increase.
        if ($errorJump >= $errorRateIncrease || ($latencyJump >= $latencyIncrease && ($after['latency'] - $before['latency']) >= 50)) {
            return 'regressed';
        }

        // Degraded: half the regression thresholds.
        if ($errorJump >= $errorRateIncrease / 2 || ($latencyJump >= $latencyIncrease / 2 && ($after['latency'] - $before['latency']) >= 30)) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Count requests / 5xx and average latency over [from, to) from the raw APM
     * request entries.
     *
     * @return WindowStats
     */
    protected function windowStats(int $from, int $to): array
    {
        if ($to <= $from) {
            return ['requests' => 0, 'errors' => 0, 'latency' => null, 'seconds' => 0];
        }

        $connection = $this->connection();

        $requestRow = $connection->table('vigilance_entries')
            ->selectRaw('count(*) as c, avg(value) as a')
            ->where('type', 'request')
            ->where('timestamp', '>=', $from)
            ->where('timestamp', '<', $to)
            ->first();

        $errors = (int) $connection->table('vigilance_entries')
            ->where('type', 'request_error')
            ->where('timestamp', '>=', $from)
            ->where('timestamp', '<', $to)
            ->count();

        $requests = (int) ($requestRow->c ?? 0);
        $avg = $requestRow?->a;

        return [
            'requests' => $requests,
            'errors' => $errors,
            'latency' => $avg !== null ? (int) round((float) $avg) : null,
            'seconds' => $to - $from,
        ];
    }

    protected function rate(int $errors, int $requests): float
    {
        return $requests > 0 ? round($errors / $requests * 100, 2) : 0.0;
    }

    /**
     * @param  WindowStats  $before
     * @param  WindowStats  $after
     */
    protected function throughputDelta(array $before, array $after): float
    {
        $beforeRate = $before['seconds'] > 0 ? $before['requests'] / $before['seconds'] : 0.0;
        $afterRate = $after['seconds'] > 0 ? $after['requests'] / $after['seconds'] : 0.0;

        if ($beforeRate <= 0.0) {
            return 0.0;
        }

        return round(($afterRate - $beforeRate) / $beforeRate * 100, 1);
    }

    protected function connection(): Connection
    {
        return DB::connection(config('vigilance.storage.connection') ?: config('database.default'));
    }
}
