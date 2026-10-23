<?php

use App\Domain\TimeTracking\MonthLockService;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverrideChange;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @param  list<User>  $users */
function timesheetLockProject(Client $client, array $users, Task ...$tasks): Project
{
    $project = Project::factory()->create(['client_id' => $client->id]);

    foreach ($tasks as $task) {
        $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    }
    foreach ($users as $user) {
        $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);
    }

    return $project;
}

function timesheetLockEntry(Project $project, Task $task, User $user, string $spentOn, float $hours = 2.0): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $spentOn,
        'hours' => $hours,
    ]);
}

function timesheetLockJuly(Client $client): MonthLock
{
    return app(MonthLockService::class)->lock(
        $client,
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-08-05'),
        User::factory()->admin()->create(),
    );
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00'));

    $this->client = Client::factory()->create(['name' => 'JDW Projects']);
    $this->task = Task::factory()->create(['name' => 'Design']);
    $this->worker = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->project = timesheetLockProject($this->client, [$this->worker, $this->admin], $this->task);
});

it('leaves a running entry running when deleting it is refused', function () {
    $entry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-07-10');
    timesheetLockJuly($this->client);
    // Locking refuses running timers, so start this one behind the observer's back.
    TimeEntry::query()->whereKey($entry->id)->update(['is_running' => true, 'timer_started_at' => now()->subHour()]);
    $this->actingAs($this->worker);

    expect(fn () => app(TimeEntryService::class)->delete($entry->fresh()))
        ->toThrow(ValidationException::class);

    $fresh = $entry->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_running)->toBeTrue()
        ->and($fresh->timer_started_at)->not->toBeNull();
});

it('rolls back an overridden write when its change log cannot be written', function (string $write) {
    $entry = timesheetLockEntry($this->project, $this->task, $this->admin, '2026-07-10');
    $lock = timesheetLockJuly($this->client);
    app(MonthLockService::class)->openOverride($lock, $this->admin, 'Correcting a mis-booked task');
    $this->actingAs($this->admin);
    MonthLockOverrideChange::creating(fn () => throw new RuntimeException('Log write failed'));

    $service = app(TimeEntryService::class);
    expect(fn () => match ($write) {
        'create' => $service->create($this->admin, [
            'project_id' => $this->project->id,
            'task_id' => $this->task->id,
            'spent_on' => '2026-07-11',
            'hours' => 1.0,
            'notes' => null,
        ]),
        'update' => $service->update($entry, ['hours' => 3.0]),
    })->toThrow(RuntimeException::class, 'Log write failed');

    expect(TimeEntry::count())->toBe(1)
        ->and((float) $entry->fresh()->hours)->toBe(2.0);
})->with(['create', 'update']);
