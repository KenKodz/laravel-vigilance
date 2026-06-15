<?php

namespace Vigilance\Logs;

/**
 * Read-side DTO for one captured log line. Returned by the storage for the log
 * explorer and the per-trace correlation panel.
 */
class LogEntry
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $id,
        public string $level,
        public int $levelValue,
        public string $message,
        public ?string $channel,
        public ?string $traceId,
        public int $loggedAt,
        public array $context = [],
    ) {}

    /**
     * Whether this line is at "error" severity or above — the explorer tints
     * these rows to make problems pop out of a noisy feed.
     */
    public function isProblem(): bool
    {
        return $this->levelValue >= LogLevel::value('error');
    }
}
