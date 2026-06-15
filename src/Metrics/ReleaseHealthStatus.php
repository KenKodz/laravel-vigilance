<?php

namespace Vigilance\Metrics;

/**
 * The health verdict for one deployment: how error rate, latency and throughput
 * moved in the window after the deploy versus the equal window before it.
 */
class ReleaseHealthStatus
{
    public function __construct(
        public int $deploymentId,
        public string $label,
        public ?string $version,
        public ?string $commit,
        public int $deployedAt,
        public string $verdict,        // no-data | healthy | degraded | regressed
        public int $requestsBefore,
        public int $requestsAfter,
        public float $errorRateBefore, // percent
        public float $errorRateAfter,  // percent
        public ?int $latencyBefore,    // avg ms
        public ?int $latencyAfter,     // avg ms
        public float $throughputDelta, // percent change, per-minute normalised
    ) {}

    public function errorRateDelta(): float
    {
        return round($this->errorRateAfter - $this->errorRateBefore, 2);
    }

    public function latencyDelta(): ?int
    {
        if ($this->latencyBefore === null || $this->latencyAfter === null) {
            return null;
        }

        return $this->latencyAfter - $this->latencyBefore;
    }

    public function latencyDeltaPercent(): ?float
    {
        if ($this->latencyBefore === null || $this->latencyAfter === null || $this->latencyBefore <= 0) {
            return null;
        }

        return round(($this->latencyAfter - $this->latencyBefore) / $this->latencyBefore * 100, 1);
    }

    public function isRegressed(): bool
    {
        return $this->verdict === 'regressed';
    }

    public function hasData(): bool
    {
        return $this->verdict !== 'no-data';
    }

    /** A short human summary of the latency move, for alert messages. */
    public function latencyLabel(): string
    {
        if ($this->latencyBefore === null || $this->latencyAfter === null) {
            return 'n/a';
        }

        $pct = $this->latencyDeltaPercent();
        $arrow = ($this->latencyDelta() ?? 0) >= 0 ? '+' : '';

        return "{$this->latencyBefore}ms→{$this->latencyAfter}ms ({$arrow}{$pct}%)";
    }
}
