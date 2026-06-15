<?php

namespace Vigilance\Notifications\Rules;

use Illuminate\Support\Carbon;
use Vigilance\Metrics\ReleaseHealth;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Deploy-regression guard: when the most recent deployment's health comes back
 * "regressed" (error rate or latency clearly up versus the window before it),
 * fire a critical "bad deploy" alert. Routed through the normal channels — point
 * a generic webhook at it to trigger an automatic rollback. Throttled per
 * deployment, and only evaluated while the deploy is still recent so it never
 * alerts forever on an old release.
 */
class ReleaseHealthRule implements AlertRule
{
    public function __construct(protected ReleaseHealth $health) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.deploy_regression.enabled', true) || ! config('vigilance.apm.enabled', true)) {
            return;
        }

        $status = $this->health->latest();

        if ($status === null || ! $status->isRegressed()) {
            return;
        }

        // Only guard a deploy while it's recent (within 2× the comparison window).
        $window = max(60, (int) config('vigilance.release_health.window_minutes', 30) * 60);

        if (Carbon::now()->getTimestamp() - $status->deployedAt > $window * 2) {
            return;
        }

        yield new Alert(
            key: 'deploy_regression:'.$status->deploymentId,
            title: 'Bad deploy: '.$status->label,
            message: sprintf(
                'Since deploying %s, error rate %+.1fpp (%.1f%%→%.1f%%) and latency %s. Consider rolling back.',
                $status->label,
                $status->errorRateDelta(),
                $status->errorRateBefore,
                $status->errorRateAfter,
                $status->latencyLabel(),
            ),
            level: 'critical',
        );
    }
}
