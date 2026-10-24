<?php

use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$fixture = __DIR__.'/../Fixtures/harvest-csv/detailed-time-fixture.csv';

beforeEach(function () {
    // The fixture CSV has users Alice Smith and Bob Jones
    User::factory()->create(['name' => 'Alice Smith', 'role_title' => null]);
    User::factory()->create(['name' => 'Bob Jones', 'role_title' => null]);

    Client::factory()->create(['name' => 'Acme Corp']);
    Client::factory()->create(['name' => 'Zeta Ltd']);

    $acme = Client::where('name', 'Acme Corp')->first();
    $zeta = Client::where('name', 'Zeta Ltd')->first();

    Project::factory()->create(['name' => 'Website Redesign', 'client_id' => $acme->id, 'code' => 'ACM001']);
    Project::factory()->create(['name' => 'Support', 'client_id' => $zeta->id, 'code' => 'ZET001']);

    Task::factory()->create(['name' => 'Development']);
    Task::factory()->create(['name' => 'Testing']);
    Task::factory()->create(['name' => 'Customer Support']);
});

it('import is idempotent — re-running does not duplicate rows', function () use ($fixture) {
    $this->artisan('harvest:import', ['path' => $fixture])->assertSuccessful();
    $this->artisan('harvest:import', ['path' => $fixture])->assertSuccessful();

    expect(TimeEntry::count())->toBe(4);
});

it('correctly maps billable fields', function () use ($fixture) {
    $this->artisan('harvest:import', ['path' => $fixture])->assertSuccessful();

    $entry = TimeEntry::where('external_reference', '10001')->first();
    expect($entry->is_billable)->toBeTrue();
    expect((float) $entry->billable_rate_snapshot)->toBe(84.0);
    expect((float) $entry->billable_amount)->toBe(168.0);
});

it('restores formula-neutralised values when re-importing our own detailed export', function () {
    $acme = Client::where('name', 'Acme Corp')->firstOrFail();
    $project = Project::factory()->create(['name' => '+Plus', 'client_id' => $acme->id, 'code' => 'PLUS1']);
    $project->tasks()->attach(Task::where('name', 'Development')->value('id'), ['is_billable' => true]);

    TimeEntry::create([
        'user_id' => User::where('name', 'Alice Smith')->value('id'),
        'project_id' => $project->id,
        'task_id' => Task::where('name', 'Development')->value('id'),
        'spent_on' => '2026-04-01',
        'hours' => 1.5,
        'notes' => '- bullet',
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => 126.0,
        'external_reference' => '99999',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'detailed-export-');
    $handle = fopen($path, 'w');
    (new DetailedTimeCsvExport(new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
    )))->writeTo($handle);
    fclose($handle);

    TimeEntry::query()->delete();

    $this->artisan('harvest:import', ['path' => $path])->assertSuccessful();
    unlink($path);

    $entry = TimeEntry::where('external_reference', '99999')->firstOrFail();
    expect($entry->notes)->toBe('- bullet')
        ->and($entry->project_id)->toBe($project->id)
        ->and(Project::where('name', "'+Plus")->exists())->toBeFalse();
});

it('dry-run writes nothing to the database', function () use ($fixture) {
    $this->artisan('harvest:import', ['path' => $fixture, '--dry-run' => true])
        ->assertSuccessful();

    expect(TimeEntry::count())->toBe(0);
});
