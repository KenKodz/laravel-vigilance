<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Http\Livewire\Incidents;
use Vigilance\Models\Incident;

uses(RefreshDatabase::class);

function makeIncident(array $attrs = []): Incident
{
    return Incident::query()->create(array_merge([
        'key' => 'k-'.uniqid(),
        'title' => 'Queue backlog',
        'message' => 'payments backing up',
        'level' => 'critical',
        'status' => 'open',
        'occurrences' => 3,
        'opened_at' => now()->subMinutes(30),
        'last_seen_at' => now(),
    ], $attrs));
}

it('lists open incidents and resolves one', function () {
    $incident = makeIncident();

    Livewire::test(Incidents::class)
        ->assertSee('Queue backlog')
        ->assertSee('open')
        ->call('resolve', $incident->id);

    expect($incident->fresh()->isResolved())->toBeTrue();
});

it('filters resolved incidents out of the open tab', function () {
    makeIncident(['title' => 'Open one']);
    makeIncident(['title' => 'Done one', 'status' => 'resolved', 'resolved_at' => now()]);

    Livewire::test(Incidents::class)
        ->assertViewHas('incidents', fn ($incidents) => $incidents->count() === 1 && $incidents->first()->title === 'Open one');
});
