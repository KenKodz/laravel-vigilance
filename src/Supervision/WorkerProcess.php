<?php

namespace Vigilance\Supervision;

use Symfony\Component\Process\Process;

/**
 * A single worker = one OS process running the host app's `queue:work`. Wraps a
 * Symfony Process and relaunches it when it exits (max-jobs/max-time/memory
 * limits, or a crash), with a 1s cooldown so a hard-crashing worker can't spin.
 */
class WorkerProcess
{
    protected ?float $restartAgainAt = null;

    public function __construct(
        public Process $process,
        public string $queue,
    ) {}

    public function start(): self
    {
        if (! $this->process->isStarted()) {
            $this->process->start();
        }

        return $this;
    }

    public function pid(): ?int
    {
        return $this->process->getPid();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Relaunch the worker if its process has exited and it isn't cooling down.
     */
    public function monitor(): void
    {
        if ($this->process->isRunning()) {
            return;
        }

        if ($this->restartAgainAt !== null && microtime(true) < $this->restartAgainAt) {
            return;
        }

        $this->restartAgainAt = microtime(true) + 1;
        $this->process = $this->process->restart();
    }

    /**
     * Graceful stop: SIGTERM, then SIGKILL after the timeout (taskkill on
     * Windows). The worker finishes its current job first.
     */
    public function terminate(int $timeout = 10): void
    {
        // Windows cannot deliver a graceful stop signal to the child, so
        // Symfony's Process::stop() blocks for the whole timeout waiting for a
        // SIGTERM that never lands, then force-kills — slow, and prone to
        // leaving orphaned workers under churn. Kill the process tree directly
        // instead: fast and reliable.
        if (PHP_OS_FAMILY === 'Windows') {
            $pid = $this->process->getPid();

            if ($pid !== null) {
                @exec('taskkill /F /T /PID '.((int) $pid).' 2>NUL');
            }

            try {
                $this->process->stop(0);
            } catch (\Throwable) {
                // already gone
            }

            return;
        }

        // POSIX: graceful SIGTERM (the worker finishes its current job), then
        // SIGKILL after the timeout, plus a defensive reap for any survivor.
        try {
            if ($this->process->isRunning()) {
                $this->process->stop($timeout);
            }
        } catch (\Throwable) {
            // already gone
        }

        try {
            if ($this->process->isRunning() && ($pid = $this->process->getPid()) !== null && function_exists('posix_kill')) {
                @posix_kill((int) $pid, 9);
            }
        } catch (\Throwable) {
            // best effort
        }
    }
}
