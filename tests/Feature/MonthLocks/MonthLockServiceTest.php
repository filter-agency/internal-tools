<?php

use App\Domain\Reporting\ClientMonthTotals;
use App\Domain\TimeTracking\MonthLockService;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverrideChange;
use App\Models\MonthLockSnapshot;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00'));
});

function lockEntry(Project $project, Task $task, string $spentOn, float $hours, array $attrs = []): TimeEntry
{
    return TimeEntry::factory()->create(array_merge([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $spentOn,
        'hours' => $hours,
    ], $attrs));
}

function lockJuly(Client $client, ?User $admin = null, string $issuedOn = '2026-08-03'): MonthLock
{
    return app(MonthLockService::class)->lock(
        $client,
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse($issuedOn),
        $admin ?? User::factory()->admin()->create(),
    );
}

it('snapshots hours by project and task when a month is locked', function () {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $otherProject = Project::factory()->create(['client_id' => $other->id]);
    $t1 = Task::factory()->create();
    $t2 = Task::factory()->create();

    // First and last day of the month both count.
    lockEntry($project, $t1, '2026-07-01', 2.0);
    lockEntry($project, $t1, '2026-07-31', 1.5);
    lockEntry($project, $t2, '2026-07-15', 3.0);
    // Next month and another client are ignored.
    lockEntry($project, $t1, '2026-08-01', 4.0);
    lockEntry($otherProject, $t1, '2026-07-10', 5.0);

    $admin = User::factory()->admin()->create();
    $lock = lockJuly($client, $admin);

    expect($lock->client_id)->toBe($client->id)
        ->and($lock->month->toDateString())->toBe('2026-07-01')
        ->and($lock->issued_on->toDateString())->toBe('2026-08-03')
        ->and($lock->locked_by_user_id)->toBe($admin->id)
        ->and($lock->label())->toBe('July 2026');

    $snapshots = MonthLockSnapshot::query()->where('month_lock_id', $lock->id)->get();
    expect($snapshots)->toHaveCount(2);

    $hours = $snapshots->mapWithKeys(
        fn (MonthLockSnapshot $s) => ["{$s->project_id}|{$s->task_id}" => (float) $s->hours],
    )->all();
    expect($hours)->toBe([
        "{$project->id}|{$t1->id}" => 3.5,
        "{$project->id}|{$t2->id}" => 3.0,
    ]);
});

it('includes entries on archived projects in the client month totals', function () {
    $client = Client::factory()->create();
    $archived = Project::factory()->create(['client_id' => $client->id, 'is_archived' => true]);
    $task = Task::factory()->create();
    lockEntry($archived, $task, '2026-07-20', 2.5);

    // Any date in the month selects that month.
    $totals = ClientMonthTotals::byProjectTask($client->id, CarbonImmutable::parse('2026-07-19'));

    expect($totals)->toBe(["{$archived->id}|{$task->id}" => 2.5]);
});

it('rejects locking a month that has not ended', function () {
    $client = Client::factory()->create();

    expect(fn () => app(MonthLockService::class)->lock(
        $client,
        CarbonImmutable::parse('2026-10-01'),
        CarbonImmutable::parse('2026-10-23'),
        User::factory()->admin()->create(),
    ))->toThrow(ValidationException::class);

    expect(MonthLock::count())->toBe(0);
});

it('allows locking from the day after the month ends and normalises any date in the month', function () {
    $client = Client::factory()->create();
    $service = app(MonthLockService::class);
    $admin = User::factory()->admin()->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-31 23:30:00'));
    expect(fn () => $service->lock($client, CarbonImmutable::parse('2026-07-19'), CarbonImmutable::parse('2026-07-31'), $admin))
        ->toThrow(ValidationException::class);

    $this->travelTo(CarbonImmutable::parse('2026-08-01 00:30:00'));
    $lock = $service->lock($client, CarbonImmutable::parse('2026-07-19'), CarbonImmutable::parse('2026-08-01'), $admin);

    expect($lock->month->toDateString())->toBe('2026-07-01')
        ->and($lock->label())->toBe('July 2026');
});

it('rejects an issued date in the future', function () {
    $client = Client::factory()->create();

    try {
        lockJuly($client, null, '2026-10-24');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('issued_on');
    }

    expect(MonthLock::count())->toBe(0);
});

it('rejects a second lock for the same client and month', function () {
    $client = Client::factory()->create();
    lockJuly($client);

    try {
        lockJuly($client);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('month');
    }

    expect(MonthLock::count())->toBe(1);
});

it('rejects locking while a timer is running in that client-month', function () {
    $client = Client::factory()->create(['name' => 'JDW Projects']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create();
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    lockEntry($project, $task, '2026-07-30', 1.0, [
        'user_id' => $jane->id,
        'is_running' => true,
        'timer_started_at' => CarbonImmutable::parse('2026-07-30 09:00:00'),
    ]);

    try {
        lockJuly($client);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['month'][0])
            ->toBe("Stop Jane Doe's running timer on JDW Projects before locking July 2026.");
    }

    expect(MonthLock::count())->toBe(0);
});

it('ignores running timers on other clients', function () {
    $client = Client::factory()->create();
    $otherProject = Project::factory()->create();
    lockEntry($otherProject, Task::factory()->create(), '2026-07-30', 1.0, [
        'is_running' => true,
        'timer_started_at' => CarbonImmutable::parse('2026-07-30 09:00:00'),
    ]);

    expect(lockJuly($client))->toBeInstanceOf(MonthLock::class);
});

it('requires an admin to lock a month', function () {
    $client = Client::factory()->create();

    expect(fn () => lockJuly($client, User::factory()->manager()->create()))
        ->toThrow(AuthorizationException::class);

    expect(MonthLock::count())->toBe(0);
});

it('opens an override that expires after 60 minutes', function () {
    $lock = lockJuly(Client::factory()->create());
    $admin = User::factory()->admin()->create();

    $override = app(MonthLockService::class)->openOverride($lock, $admin, 'Correcting a mis-booked task');

    expect($override->month_lock_id)->toBe($lock->id)
        ->and($override->user_id)->toBe($admin->id)
        ->and($override->reason)->toBe('Correcting a mis-booked task')
        ->and($override->expires_at->equalTo(now()->addMinutes(60)))->toBeTrue()
        ->and($override->closed_at)->toBeNull()
        ->and($override->isActive())->toBeTrue()
        ->and($lock->activeOverrideFor($admin)?->is($override))->toBeTrue();

    $this->travel(61)->minutes();

    expect($override->fresh()->isActive())->toBeFalse()
        ->and($lock->activeOverrideFor($admin))->toBeNull();
});

it('requires an admin and a reason of at least 10 characters to open an override', function () {
    $lock = lockJuly(Client::factory()->create());
    $service = app(MonthLockService::class);

    expect(fn () => $service->openOverride($lock, User::factory()->manager()->create(), 'A perfectly good reason'))
        ->toThrow(AuthorizationException::class);

    $admin = User::factory()->admin()->create();

    try {
        $service->openOverride($lock, $admin, 'too short');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('reason');
    }

    expect(fn () => $service->openOverride($lock, $admin, '   padded   '))
        ->toThrow(ValidationException::class);

    expect($lock->overrides()->count())->toBe(0)
        ->and($service->openOverride($lock, $admin, '0123456789')->reason)->toBe('0123456789');
});

it('closes the same admin\'s other active override when opening a new one', function () {
    $lock = lockJuly(Client::factory()->create());
    $service = app(MonthLockService::class);
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $first = $service->openOverride($lock, $admin, 'First correction pass');
    $others = $service->openOverride($lock, $otherAdmin, 'Other admin working');
    $second = $service->openOverride($lock, $admin, 'Second correction pass');

    expect($first->fresh()->closed_at)->not->toBeNull()
        ->and($first->fresh()->isActive())->toBeFalse()
        ->and($second->isActive())->toBeTrue()
        ->and($others->fresh()->isActive())->toBeTrue()
        ->and($lock->activeOverrideFor($admin)?->is($second))->toBeTrue()
        ->and($lock->activeOverrideFor($otherAdmin)?->is($others))->toBeTrue();
});

it('closes an override', function () {
    $lock = lockJuly(Client::factory()->create());
    $service = app(MonthLockService::class);
    $admin = User::factory()->admin()->create();
    $override = $service->openOverride($lock, $admin, 'Correcting a mis-booked task');

    $service->closeOverride($override);

    expect($override->fresh()->closed_at)->not->toBeNull()
        ->and($override->fresh()->isActive())->toBeFalse()
        ->and($lock->activeOverrideFor($admin))->toBeNull();
});

it('keeps the override log when something tries to delete a lock or an override', function () {
    $lock = lockJuly(Client::factory()->create());
    $override = app(MonthLockService::class)->openOverride($lock, User::factory()->admin()->create(), 'Correcting a mis-booked task');
    $change = MonthLockOverrideChange::create([
        'month_lock_override_id' => $override->id,
        'time_entry_id' => 1,
        'action' => 'updated',
        'before' => ['hours' => 2.0],
        'after' => ['hours' => 3.0],
    ]);

    expect(fn () => $lock->delete())->toThrow(QueryException::class)
        ->and(fn () => $override->delete())->toThrow(QueryException::class);

    expect($lock->fresh())->not->toBeNull()
        ->and($override->fresh())->not->toBeNull()
        ->and($change->fresh())->not->toBeNull();
});
