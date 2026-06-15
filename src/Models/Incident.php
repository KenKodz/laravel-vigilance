<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * An incident: a fired alert that stays "open" while its condition recurs and
 * auto-resolves once it stops. Tracks occurrences and time-to-resolution (MTTR).
 *
 * @property int $id
 * @property string $key
 * @property string $title
 * @property ?string $message
 * @property string $level
 * @property string $status
 * @property int $occurrences
 * @property ?Carbon $opened_at
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $resolved_at
 */
class Incident extends VigilanceModel
{
    protected $table = 'vigilance_incidents';

    protected $casts = [
        'occurrences' => 'integer',
        'opened_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** Duration so far (open) or until resolution (resolved), in seconds. */
    public function durationSeconds(): int
    {
        if ($this->opened_at === null) {
            return 0;
        }

        return (int) abs($this->opened_at->diffInSeconds($this->resolved_at ?? Carbon::now()));
    }
}
