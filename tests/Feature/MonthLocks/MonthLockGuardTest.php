<?php

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\TimeTracking\MonthLockGuard;
use App\Domain\TimeTracking\MonthLockService;
use App\Domain\TimeTracking\TimeEntryService;
use App\Livewire\Admin\Projects\Edit as ProjectEdit;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverride;
use App\Models\MonthLockOverrideChange;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

const GUARD_STANDARD_MESSAGE = 'July 2026 is locked for JDW Projects: the report was issued on 5 August 2026. Ask an admin to make this change.';
const GUARD_ADMIN_MESSAGE = 'July 2026 is locked for JDW Projects. Open an override in Admin > Month locks first.';

function guardProject(Client $client, Task $task, User $user): Project
{
    $project = Project::factory()->create(['client_id' => $client->id]);
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);

    return $project;
}

function guardEntry(Project $project, Task $task, User $user, string $spentOn, float $hours = 2.0): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $spentOn,
        'hours' => $hours,
    ]);
}

function guardLock(Client $client, string $month, string $issuedOn): MonthLock
{
    return app(MonthLockService::class)->lock(
        $client,
        CarbonImmutable::parse($month),
        CarbonImmutable::parse($issuedOn),
        User::factory()->admin()->create(),
    );
}

function guardOverride(MonthLock $lock, User $admin, string $reason = 'Correcting a mis-booked task'): MonthLockOverride
{
    return app(MonthLockService::class)->openOverride($lock, $admin, $reason);
}

/** @return array<string, array<int, string>> */
function guardErrors(callable $write): array
{
    try {
        $write();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    Assert::fail('Expected a ValidationException.');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00'));

    $this->client = Client::factory()->create(['name' => 'JDW Projects']);
    $this->task = Task::factory()->create();
    $this->worker = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->project = guardProject($this->client, $this->task, $this->worker);
    $this->entry = guardEntry($this->project, $this->task, $this->worker, '2026-07-10');
    $this->july = guardLock($this->client, '2026-07-01', '2026-08-05');
});

it('rejects a standard user editing an entry in a locked month', function () {
    $this->actingAs($this->worker);

    $errors = guardErrors(fn () => app(TimeEntryService::class)->update($this->entry, ['hours' => 3.0]));

    expect($errors)->toHaveKey('month_lock')
        ->and($errors['month_lock'][0])->toBe(GUARD_STANDARD_MESSAGE)
        ->and((float) $this->entry->fresh()->hours)->toBe(2.0);
});

it('rejects creating an entry in a locked month', function () {
    $this->actingAs($this->worker);
    $service = app(TimeEntryService::class);
    $data = ['project_id' => $this->project->id, 'task_id' => $this->task->id, 'hours' => 1.0, 'notes' => null];

    $errors = guardErrors(fn () => $service->create($this->worker, [...$data, 'spent_on' => '2026-07-31']));

    expect($errors['month_lock'][0])->toContain('July 2026 is locked')
        ->and(TimeEntry::count())->toBe(1);

    // The next month is not locked.
    $service->create($this->worker, [...$data, 'spent_on' => '2026-08-01']);
    expect(TimeEntry::count())->toBe(2);
});

it('rejects a manager the same way as a standard user', function () {
    $this->actingAs(User::factory()->manager()->create());

    $errors = guardErrors(fn () => app(TimeEntryService::class)->update($this->entry, ['hours' => 3.0]));

    expect($errors['month_lock'][0])->toBe(GUARD_STANDARD_MESSAGE)
        ->and((float) $this->entry->fresh()->hours)->toBe(2.0);
});

it('rejects moving an entry out of a locked month', function () {
    $this->actingAs($this->worker);

    $errors = guardErrors(fn () => app(TimeEntryService::class)->update($this->entry, ['spent_on' => '2026-08-03']));

    expect($errors['month_lock'][0])->toContain('July 2026 is locked')
        ->and($this->entry->fresh()->spent_on->toDateString())->toBe('2026-07-10');
});

it('rejects moving an entry into a locked month from an unlocked client', function () {
    $this->actingAs($this->worker);
    $otherProject = guardProject(Client::factory()->create(), $this->task, $this->worker);
    $entry = guardEntry($otherProject, $this->task, $this->worker, '2026-07-15');

    $errors = guardErrors(fn () => app(TimeEntryService::class)->update($entry, ['project_id' => $this->project->id]));

    expect($errors['month_lock'][0])->toContain('July 2026 is locked')
        ->and($entry->fresh()->project_id)->toBe($otherProject->id);
});

it('rejects an admin without an open override', function () {
    $this->actingAs($this->admin);

    $errors = guardErrors(fn () => app(TimeEntryService::class)->update($this->entry, ['hours' => 3.0]));

    expect($errors['month_lock'][0])->toBe(GUARD_ADMIN_MESSAGE)
        ->and((float) $this->entry->fresh()->hours)->toBe(2.0);
});

it('allows an admin with an open override and logs who, when and why', function () {
    $override = guardOverride($this->july, $this->admin);
    $this->actingAs($this->admin);

    app(TimeEntryService::class)->update($this->entry, ['hours' => 3.0]);

    expect((float) $this->entry->fresh()->hours)->toBe(3.0);

    $changes = MonthLockOverrideChange::with('override')->get();
    expect($changes)->toHaveCount(1);

    $change = $changes->first();
    expect($change->month_lock_override_id)->toBe($override->id)
        ->and($change->time_entry_id)->toBe($this->entry->id)
        ->and($change->action)->toBe('updated')
        ->and($change->before['hours'])->toEqual(2.0)
        ->and($change->after['hours'])->toEqual(3.0)
        ->and($change->before['spent_on'])->toBe('2026-07-10')
        ->and($change->created_at->equalTo(now()))->toBeTrue()
        ->and($change->override->user_id)->toBe($this->admin->id)
        ->and($change->override->reason)->toBe('Correcting a mis-booked task');
});

it('logs a deleted entry with its before values', function () {
    $override = guardOverride($this->july, $this->admin);
    $this->actingAs($this->admin);
    $entryId = $this->entry->id;

    app(TimeEntryService::class)->delete($this->entry);

    expect(TimeEntry::find($entryId))->toBeNull();

    $change = MonthLockOverrideChange::sole();
    expect($change->month_lock_override_id)->toBe($override->id)
        ->and($change->time_entry_id)->toBe($entryId)
        ->and($change->action)->toBe('deleted')
        ->and($change->before['hours'])->toEqual(2.0)
        ->and($change->before['project_id'])->toBe($this->project->id)
        ->and($change->after)->toBeNull();
});

it('rejects a standard user restarting a timer on an entry in a locked month', function () {
    $this->actingAs($this->worker);
    // Restarting auto-stops this timer first; the refusal must roll that back.
    $august = guardEntry($this->project, $this->task, $this->worker, '2026-08-03', 1.0);
    $august->update(['is_running' => true, 'timer_started_at' => now()->subHour()]);

    $errors = guardErrors(fn () => app(TimeEntryService::class)->startTimer($this->entry));

    expect($errors['month_lock'][0])->toBe(GUARD_STANDARD_MESSAGE)
        ->and($this->entry->fresh()->is_running)->toBeFalse()
        ->and($august->fresh()->is_running)->toBeTrue()
        ->and((float) $august->fresh()->hours)->toBe(1.0);
});

it('allows an admin with an open override to restart a timer and logs it', function () {
    $override = guardOverride($this->july, $this->admin);
    $this->actingAs($this->admin);

    app(TimeEntryService::class)->startTimer($this->entry);

    expect($this->entry->fresh()->is_running)->toBeTrue();

    $change = MonthLockOverrideChange::sole();
    expect($change->month_lock_override_id)->toBe($override->id)
        ->and($change->time_entry_id)->toBe($this->entry->id)
        ->and($change->action)->toBe('updated')
        ->and($change->before['is_running'])->toBeFalse()
        ->and($change->after['is_running'])->toBeTrue()
        ->and($change->after['hours'])->toEqual(2.0);
});

it('rejects an admin whose override covers only the source month', function () {
    guardLock($this->client, '2026-08-01', '2026-09-04');
    guardOverride($this->july, $this->admin);
    $this->actingAs($this->admin);

    $errors = guardErrors(fn () => app(TimeEntryService::class)->update($this->entry, ['spent_on' => '2026-08-03']));

    expect($errors['month_lock'][0])->toBe('August 2026 is locked for JDW Projects. Open an override in Admin > Month locks first.')
        ->and($this->entry->fresh()->spent_on->toDateString())->toBe('2026-07-10')
        ->and(MonthLockOverrideChange::count())->toBe(0);
});

it('allows an admin over MCP when the override is open', function () {
    guardOverride($this->july, $this->admin);
    // MCP requests authenticate on the api guard only; the default guard stays empty.
    Auth::guard('api')->setUser($this->admin);
    expect(auth()->user())->toBeNull();

    app(InternalMcpActions::class)->updateTimeEntry($this->admin, $this->entry, ['hours' => '3']);

    expect((float) $this->entry->fresh()->hours)->toBe(3.0);

    $change = MonthLockOverrideChange::sole();
    expect($change->action)->toBe('updated')
        ->and($change->after['hours'])->toEqual(3.0);
});

it('ignores Asana sync metadata updates on locked entries', function () {
    expect(auth()->user())->toBeNull();

    $this->entry->update([
        'asana_synced_at' => now(),
        'asana_sync_error' => 'Rate limited',
        'invoiced_at' => now(),
    ]);

    $fresh = $this->entry->fresh();
    expect($fresh->asana_synced_at)->not->toBeNull()
        ->and($fresh->asana_sync_error)->toBe('Rate limited')
        ->and($fresh->invoiced_at)->not->toBeNull()
        ->and(MonthLockOverrideChange::count())->toBe(0);
});

it('rejects changing a project client when the project has time in a locked month', function () {
    $this->actingAs($this->admin);
    $other = Client::factory()->create();
    $message = 'This project has time in July 2026, which is locked for JDW Projects, so its client cannot be changed.';

    // Out of the locked client.
    $errors = guardErrors(fn () => $this->project->update(['client_id' => $other->id]));
    expect($errors['client_id'][0])->toBe($message)
        ->and($this->project->fresh()->client_id)->toBe($this->client->id);

    // Into the locked client.
    $incoming = guardProject($other, $this->task, $this->worker);
    guardEntry($incoming, $this->task, $this->worker, '2026-07-20');
    $errors = guardErrors(fn () => $incoming->update(['client_id' => $this->client->id]));
    expect($errors['client_id'][0])->toBe($message)
        ->and($incoming->fresh()->client_id)->toBe($other->id);

    // The admin project page shows the refusal beside the client select.
    Livewire::test(ProjectEdit::class, ['project' => $this->project->fresh()])
        ->set('clientId', $other->id)
        ->call('save')
        ->assertHasErrors('client_id')
        ->assertSee('which is locked for JDW Projects');
    expect($this->project->fresh()->client_id)->toBe($this->client->id);

    // Time outside locked months does not block the change.
    $unlocked = guardProject($other, $this->task, $this->worker);
    guardEntry($unlocked, $this->task, $this->worker, '2026-08-20');
    $unlocked->update(['client_id' => $this->client->id]);
    expect($unlocked->fresh()->client_id)->toBe($this->client->id);
});

it('finds locks by client-month and lists them for a date range', function () {
    $guard = app(MonthLockGuard::class);
    $other = Client::factory()->create();
    $august = guardLock($other, '2026-08-01', '2026-09-02');
    guardLock($this->client, '2026-09-01', '2026-10-02');

    expect($guard->lockFor($this->client->id, CarbonImmutable::parse('2026-07-31'))?->is($this->july))->toBeTrue()
        ->and($guard->lockFor($this->client->id, CarbonImmutable::parse('2026-08-01')))->toBeNull()
        ->and($guard->lockFor($other->id, CarbonImmutable::parse('2026-07-15')))->toBeNull();

    $week = $guard->lockedClientMonths(CarbonImmutable::parse('2026-07-27'), CarbonImmutable::parse('2026-08-02'));
    expect(array_keys($week))->toEqualCanonicalizing(["{$this->client->id}|2026-07", "{$other->id}|2026-08"])
        ->and($week["{$other->id}|2026-08"]->is($august))->toBeTrue();

    // A single-day range on the first of the month still finds that month.
    expect(array_keys($guard->lockedClientMonths(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-01'))))
        ->toBe(["{$other->id}|2026-08"]);
});
