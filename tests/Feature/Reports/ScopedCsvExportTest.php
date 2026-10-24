<?php

use App\Enums\Role;
use App\Livewire\Reports\ClientsReport;
use App\Livewire\Reports\TimeReport;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function scopedExportEntry(array $attrs): TimeEntry
{
    return TimeEntry::create(array_merge([
        'spent_on' => '2026-04-15',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 100.0,
        'billable_amount' => 100.0,
        'invoiced_at' => null,
    ], $attrs));
}

function captureStreamBody($response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

test('exportForClient on ClientsReport scopes the CSV to that client only', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $clientA = Client::factory()->create(['name' => 'Acme Co']);
    $clientB = Client::factory()->create(['name' => 'Beta Ltd']);
    $projectA = Project::factory()->create(['client_id' => $clientA->id]);
    $projectB = Project::factory()->create(['client_id' => $clientB->id]);

    scopedExportEntry(['user_id' => $user->id, 'project_id' => $projectA->id, 'task_id' => $task->id, 'notes' => 'A entry']);
    scopedExportEntry(['user_id' => $user->id, 'project_id' => $projectB->id, 'task_id' => $task->id, 'notes' => 'B entry']);

    $this->actingAs($admin);

    $component = Livewire::test(ClientsReport::class)
        ->set('from', '2026-04-01')
        ->set('to', '2026-04-30');

    $response = $component->instance()->exportForClient($clientA->id);
    $body = captureStreamBody($response);

    expect($body)->toContain('A entry')->not->toContain('B entry');
    expect($response->headers->get('Content-Disposition'))->toContain('acme-co');
});

function archivedDefaultJulyReport(): array
{
    $admin = User::factory()->create(['role' => Role::Admin]);
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $client = Client::factory()->create(['name' => 'Acme Co']);
    $active = Project::factory()->create(['client_id' => $client->id, 'name' => 'Live project']);
    $archived = Project::factory()->create(['client_id' => $client->id, 'name' => 'Archived project', 'is_archived' => true]);

    scopedExportEntry(['user_id' => $user->id, 'project_id' => $active->id, 'task_id' => $task->id, 'spent_on' => '2026-07-10', 'hours' => 1.0]);
    scopedExportEntry(['user_id' => $user->id, 'project_id' => $archived->id, 'task_id' => $task->id, 'spent_on' => '2026-07-11', 'hours' => 2.0]);

    test()->actingAs($admin);

    return [$client, $active, $archived];
}

it('includes archived project time in report totals and exports by default', function () {
    archivedDefaultJulyReport();

    $component = Livewire::test(TimeReport::class)
        ->set('preset', 'custom')
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31')
        ->assertSet('showArchived', true);

    expect($component->instance()->totals()->totalHours)->toBe(3.0);

    $body = captureStreamBody($component->instance()->export());

    expect($body)->toContain('Archived project')->toContain('Live project');
});

it('leaves archived project time out of totals and exports when the checkbox is unticked', function () {
    archivedDefaultJulyReport();

    $component = Livewire::test(TimeReport::class)
        ->set('preset', 'custom')
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31')
        ->set('showArchived', false);

    expect($component->instance()->totals()->totalHours)->toBe(1.0);

    $body = captureStreamBody($component->instance()->export());

    expect($body)->toContain('Live project')->not->toContain('Archived project');
});

it('names the file excluding-archived when archived projects are hidden', function () {
    archivedDefaultJulyReport();

    Livewire::test(TimeReport::class)
        ->set('preset', 'custom')
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31')
        ->set('showArchived', false)
        ->call('export')
        ->assertFileDownloaded('detailed-time-2026-07-01-to-2026-07-31-excluding-archived.csv');
});

it('keeps the plain file name when archived projects are included', function () {
    archivedDefaultJulyReport();

    Livewire::test(TimeReport::class)
        ->set('preset', 'custom')
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31')
        ->call('export')
        ->assertFileDownloaded('detailed-time-2026-07-01-to-2026-07-31.csv');
});

it('adds the excluding-archived suffix after the client scope', function () {
    [$client] = archivedDefaultJulyReport();

    $response = Livewire::test(ClientsReport::class)
        ->set('preset', 'custom')
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31')
        ->set('showArchived', false)
        ->instance()
        ->exportForClient($client->id);

    expect($response->headers->get('Content-Disposition'))
        ->toContain('detailed-time-acme-co-2026-07-01-to-2026-07-31-excluding-archived.csv');
});
