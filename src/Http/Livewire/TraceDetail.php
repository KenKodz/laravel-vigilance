<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Tracing\Contracts\TraceStorage;

/**
 * A single trace's waterfall: every span laid out on a timeline relative to the
 * trace duration, plus the application logs emitted inside it.
 */
class TraceDetail extends Component
{
    public string $traceId;

    public function mount(string $trace): void
    {
        $this->traceId = $trace;
    }

    public function render()
    {
        $trace = app(TraceStorage::class)->find($this->traceId);

        abort_if($trace === null, 404);

        return view('vigilance::pages.trace-detail', [
            'trace' => $trace,
            'logs' => app(LogStorage::class)->forTrace($this->traceId),
        ])->layout('vigilance::layout', ['title' => 'Trace']);
    }
}
