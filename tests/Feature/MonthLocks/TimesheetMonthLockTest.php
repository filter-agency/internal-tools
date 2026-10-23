<?php

use App\Domain\TimeTracking\HoursFormatter;
use App\Domain\TimeTracking\MonthLockService;
use App\Domain\TimeTracking\TimeEntryService;
use App\Livewire\Admin\TimeEntries\BulkMove;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverrideChange;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const TIMESHEET_LOCK_TITLE = 'July 2026 is locked: report issued 5 August 2026';
const TIMESHEET_LOCK_STANDARD_MESSAGE = 'July 2026 is locked for JDW Projects: the report was issued on 5 August 2026. Ask an admin to make this change.';
const TIMESHEET_LOCK_ADMIN_MESSAGE = 'July 2026 is locked for JDW Projects. Open an override in Admin &gt; Month locks first.';

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

/**
 * DayView finds the day's entries by direct DATE equality. SQLite stores the
 * date cast with a time, so store this entry's date the way MySQL does.
 */
function timesheetLockDayEntry(Project $project, Task $task, User $user, string $spentOn): TimeEntry
{
    $entry = timesheetLockEntry($project, $task, $user, $spentOn);
    TimeEntry::query()->whereKey($entry->id)->update(['spent_on' => $spentOn]);

    return $entry;
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

function timesheetLockRowKey(Project $project, Task $task): string
{
    return 'p'.$project->id.'_t'.$task->id.'_anone';
}

function timesheetLockBadgeCount(string $html): int
{
    return substr_count($html, '>Locked</span>');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00'));

    $this->client = Client::factory()->create(['name' => 'JDW Projects']);
    $this->task = Task::factory()->create(['name' => 'Design']);
    $this->worker = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->project = timesheetLockProject($this->client, [$this->worker, $this->admin], $this->task);
});

it('refuses a standard user saving an edit to a locked entry in the day view', function () {
    $entry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-07-10');
    timesheetLockJuly($this->client);
    $this->actingAs($this->worker);

    Livewire::test(DayView::class)
        ->set('selectedDate', '2026-07-10')
        ->call('openEditModal', $entry->id)
        ->set('hoursInput', '3:00')
        ->set('notes', 'Changed')
        ->call('save')
        ->assertHasErrors('month_lock')
        ->assertSee(TIMESHEET_LOCK_STANDARD_MESSAGE);

    $fresh = $entry->fresh();
    expect((float) $fresh->hours)->toBe(2.0)
        ->and($fresh->notes)->toBeNull();
});

it('marks locked entries in the day view for the owner', function () {
    timesheetLockDayEntry($this->project, $this->task, $this->worker, '2026-07-10');
    $otherClient = Client::factory()->create();
    timesheetLockDayEntry(timesheetLockProject($otherClient, [$this->worker], $this->task), $this->task, $this->worker, '2026-07-10');
    timesheetLockJuly($this->client);
    $this->actingAs($this->worker);

    $component = Livewire::test(DayView::class)
        ->set('selectedDate', '2026-07-10')
        ->assertSee(TIMESHEET_LOCK_TITLE);

    // Only the locked client's entry is marked.
    expect(timesheetLockBadgeCount($component->html()))->toBe(1);
});

it('does not mark locked entries for an admin with an open override', function () {
    timesheetLockDayEntry($this->project, $this->task, $this->worker, '2026-07-10');
    $lock = timesheetLockJuly($this->client);
    $this->actingAs($this->admin);

    // The viewer is the signed-in admin, not the timesheet owner.
    Livewire::test(DayView::class, ['user' => $this->worker])
        ->set('selectedDate', '2026-07-10')
        ->assertSee('Locked');

    app(MonthLockService::class)->openOverride($lock, $this->admin, 'Correcting a mis-booked task');

    Livewire::test(DayView::class, ['user' => $this->worker])
        ->set('selectedDate', '2026-07-10')
        ->assertDontSee('Locked');
});

it('saves nothing from the week when one changed cell is in a locked month', function () {
    $build = Task::factory()->create(['name' => 'Build']);
    $this->project->tasks()->attach($build->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    $julyEntry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-07-28');
    timesheetLockJuly($this->client);
    $this->actingAs($this->worker);

    $augustRow = timesheetLockRowKey($this->project, $build);
    $julyRow = timesheetLockRowKey($this->project, $this->task);

    Livewire::test(WeekView::class)
        ->set('selectedDate', '2026-07-27')
        ->set('extraRows', [$augustRow])
        // The August row comes first, so its new entry is written before the
        // locked July cell is refused.
        ->set('cellValues', [
            $augustRow => ['', '', '', '', '', '1:00', ''],
            $julyRow => ['', '3:00', '', '', '', '', ''],
        ])
        ->call('save')
        ->assertHasErrors('month_lock')
        ->assertSee(TIMESHEET_LOCK_STANDARD_MESSAGE)
        ->assertDontSee('Saved.');

    expect(TimeEntry::count())->toBe(1)
        ->and((float) $julyEntry->fresh()->hours)->toBe(2.0)
        ->and(TimeEntry::whereDate('spent_on', '2026-08-01')->exists())->toBeFalse();
});

it('saves the week around an untouched locked cell whose h:mm value does not round-trip', function () {
    // 1.09h shows as 1:05, which parses back as 1.08h.
    $julyEntry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-07-28', 1.09);
    $augustEntry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-08-01', 1.0);
    timesheetLockJuly($this->client);
    $this->worker->update(['schedule_preferences' => ['hours_display_format' => HoursFormatter::FORMAT_HHMM]]);
    $this->actingAs($this->worker);
    $row = timesheetLockRowKey($this->project, $this->task);

    Livewire::test(WeekView::class)
        ->set('selectedDate', '2026-07-27')
        ->assertSet("cellValues.{$row}.1", '1:05')
        ->set("cellValues.{$row}.5", '2:00')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $julyEntry->fresh()->hours)->toBe(1.09)
        ->and((float) $augustEntry->fresh()->hours)->toBe(2.0);
});

it('keeps the stored hours when an admin under an override edits only the notes', function () {
    // Created through the service so an update recomputes the same billable values.
    $entry = app(TimeEntryService::class)->create($this->admin, [
        'project_id' => $this->project->id,
        'task_id' => $this->task->id,
        'spent_on' => '2026-07-10',
        'hours' => 1.09,
        'notes' => null,
    ]);
    $lock = timesheetLockJuly($this->client);
    app(MonthLockService::class)->openOverride($lock, $this->admin, 'Correcting a mis-booked task');
    $this->actingAs($this->admin);

    Livewire::test(DayView::class)
        ->set('selectedDate', '2026-07-10')
        ->call('openEditModal', $entry->id)
        ->assertSet('hoursInput', '1:05')
        ->set('notes', 'Clarified scope')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $entry->fresh();
    expect((float) $fresh->hours)->toBe(1.09)
        ->and($fresh->notes)->toBe('Clarified scope')
        ->and(MonthLockOverrideChange::sole()->changedFieldLabels())->toBe(['Notes']);
});

it('marks and disables locked cells in the week view', function () {
    timesheetLockEntry($this->project, $this->task, $this->worker, '2026-07-28');
    timesheetLockEntry($this->project, $this->task, $this->worker, '2026-08-01');
    $otherClient = Client::factory()->create();
    timesheetLockEntry(timesheetLockProject($otherClient, [$this->worker], $this->task), $this->task, $this->worker, '2026-07-28');
    timesheetLockJuly($this->client);
    $this->actingAs($this->worker);

    $html = Livewire::test(WeekView::class)
        ->set('selectedDate', '2026-07-27')
        ->assertSee(TIMESHEET_LOCK_TITLE)
        ->html();

    // Monday 27 to Friday 31 July on the locked client's row only.
    $disabledCells = preg_match_all('/<input[^>]*wire:model\.live\.blur="cellValues[^"]*"[^>]*\sdisabled[\s>]/', $html);
    expect(timesheetLockBadgeCount($html))->toBe(5)
        ->and($disabledCells)->toBe(5);
});

it('keeps every entry in a week row when removing it hits a locked month', function () {
    // June is open and comes first, so it is deleted before July is refused.
    $juneEntry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-06-30');
    $julyEntry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-07-01');
    timesheetLockJuly($this->client);
    $this->actingAs($this->worker);

    Livewire::test(WeekView::class)
        ->set('selectedDate', '2026-06-29')
        ->call('removeRow', timesheetLockRowKey($this->project, $this->task))
        ->assertHasErrors('month_lock')
        ->assertSee(TIMESHEET_LOCK_STANDARD_MESSAGE);

    expect($juneEntry->fresh())->not->toBeNull()
        ->and($julyEntry->fresh())->not->toBeNull();
});

it('refuses a bulk move out of a locked month', function () {
    $entry = timesheetLockEntry($this->project, $this->task, $this->worker, '2026-07-10');
    timesheetLockJuly($this->client);
    $destination = timesheetLockProject(Client::factory()->create(), [$this->worker], $this->task);
    $this->actingAs($this->admin);

    Livewire::test(BulkMove::class)
        ->set('filterFrom', '2026-07-01')
        ->set('filterTo', '2026-07-31')
        ->set('selected', [$entry->id])
        ->set('destinationProjectId', $destination->id)
        ->set('destinationTaskId', $this->task->id)
        ->call('move')
        ->assertHasErrors('month_lock')
        ->assertSeeHtml(TIMESHEET_LOCK_ADMIN_MESSAGE);

    expect($entry->fresh()->project_id)->toBe($this->project->id)
        ->and(TimeEntryAudit::count())->toBe(0);
});

it('refuses an API time entry in a locked month with a 422', function () {
    timesheetLockJuly($this->client);
    $token = PersonalAccessToken::generate($this->worker, 'test')['token'];

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/time-entries', [
            'project_id' => $this->project->id,
            'task_id' => $this->task->id,
            'spent_on' => '2026-07-15',
            'hours' => '1:00',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.month_lock.0', TIMESHEET_LOCK_STANDARD_MESSAGE);

    expect(TimeEntry::count())->toBe(0);
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
