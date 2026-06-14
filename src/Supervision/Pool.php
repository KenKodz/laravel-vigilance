<?php

namespace Vigilance\Supervision;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A pool of worker processes for one (supervisor, queue) pair. Knows how to
 * scale itself up/down to a target size and to keep its workers alive.
 */
class Pool
{
    /** @var list<WorkerProcess> */
    protected array $workers = [];

    public function __construct(
        public string $key,
        protected SupervisorOptions $options,
    ) {}

    public function count(): int
    {
        return count($this->workers);
    }

    /**
     * Bring the pool to exactly $target processes (scale up by launching,
     * scale down by gracefully terminating the surplus).
     */
    public function scaleTo(int $target): void
    {
        $target = max(0, $target);

        while (count($this->workers) < $target) {
            $this->workers[] = $this->launch();
        }

        while (count($this->workers) > $target) {
            $worker = array_pop($this->workers);
            $worker->terminate($this->options->timeout);
        }

        // Fully stopping the pool (e.g. on pause): sweep up any worker that
        // escaped a per-process kill so none keep draining the queue.
        if ($target === 0) {
            $this->reap();
        }
    }

    /**
     * Restart any worker whose process has exited.
     */
    public function monitor(): void
    {
        foreach ($this->workers as $worker) {
            $worker->monitor();
        }
    }

    public function terminate(): void
    {
        foreach ($this->workers as $worker) {
            $worker->terminate($this->options->timeout);
        }

        $this->workers = [];

        $this->reap();
    }

    /**
     * Windows-only safety net: kill any of THIS supervisor's worker processes
     * that survived a per-process stop (taskkill PID races, wrapper PIDs, or a
     * worker launched in the same tick it was told to stop). Workers are matched
     * by their "#vigilance"-tagged --name, so unrelated queue:work processes are
     * never touched. POSIX relies on Process::stop()'s signal + (under systemd)
     * cgroup reaping, so this is a no-op there.
     */
    protected function reap(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        $marker = '--name='.$this->options->workerName();

        $ps = 'Get-CimInstance Win32_Process -Filter "Name=\'php.exe\'" '
            .'| Where-Object { $_.CommandLine -like \'*'.$marker.'*\' } '
            .'| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }';

        // -EncodedCommand (UTF-16LE base64) sidesteps all cmd.exe quoting issues.
        $utf16 = '';
        foreach (str_split($ps) as $ch) {
            $utf16 .= $ch."\x00";
        }

        @exec('powershell -NoProfile -ExecutionPolicy Bypass -EncodedCommand '.base64_encode($utf16).' 2>&1');
    }

    /**
     * Live worker descriptors for the heartbeat.
     *
     * @return list<array{pid: int, queue: string}>
     */
    public function descriptors(): array
    {
        $out = [];

        foreach ($this->workers as $worker) {
            $pid = $worker->pid();

            if ($pid !== null) {
                $out[] = ['pid' => $pid, 'queue' => $this->key];
            }
        }

        return $out;
    }

    /**
     * Live worker PIDs in this pool.
     *
     * @return list<int>
     */
    public function pids(): array
    {
        $out = [];

        foreach ($this->workers as $worker) {
            $pid = $worker->pid();

            if ($pid !== null) {
                $out[] = $pid;
            }
        }

        return $out;
    }

    protected function launch(): WorkerProcess
    {
        $command = array_merge(
            $this->options->niceWrapper(),
            [$this->phpBinary(), base_path('artisan')],
            $this->options->workerCommand($this->key),
        );

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->disableOutput();

        return (new WorkerProcess($process, $this->key))->start();
    }

    protected function phpBinary(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
    }
}
