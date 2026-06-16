<?php

namespace Vigilance\Http\Livewire;

use Illuminate\Support\Collection;
use Livewire\Component;
use Vigilance\Models\WorkerRecord;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Supervision\SupervisorState;

/**
 * Live view of the running supervisors and their worker processes, with
 * pause / resume / restart controls wired to the ControlPlane. The "queue:work
 * but supervised" replacement for Horizon's dashboard.
 */
class Workers extends Component
{
    public function pause(): void
    {
        app(ControlPlane::class)->pause();
        $this->flash('Supervisors paused — workers will stop processing.');
    }

    public function resume(): void
    {
        app(ControlPlane::class)->continue();
        $this->flash('Supervisors resumed.');
    }

    public function restart(): void
    {
        app(ControlPlane::class)->restart();
        $this->flash('Workers will restart gracefully.');
    }

    protected function flash(string $message): void
    {
        session()->flash('vigilance.flash', ['type' => 'success', 'message' => $message]);
    }

    public function render()
    {
        $expire = (int) config('vigilance.supervision.heartbeat_expire', 30);

        $supervisors = app(SupervisorState::class)->active($expire);

        // Group workers by (supervisor, host) so each node's card shows only its
        // own workers — names repeat across nodes in a multi-node fleet.
        /** @var Collection<string, Collection<int, WorkerRecord>> $workers */
        $workers = WorkerRecord::query()
            ->orderBy('supervisor')
            ->orderBy('pid')
            ->get(['supervisor', 'host', 'pid', 'queue', 'connection', 'status'])
            ->groupBy(fn (WorkerRecord $w) => $w->supervisor.'@'.$w->host);

        return view('vigilance::pages.workers', [
            'control' => app(ControlPlane::class)->status(),
            'controlEnabled' => (bool) config('vigilance.supervision', true),
            'supervisors' => $supervisors,
            'workers' => $workers,
        ])->layout('vigilance::layout', ['title' => 'Workers']);
    }
}
