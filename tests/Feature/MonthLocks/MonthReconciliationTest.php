<?php

use App\Domain\Reporting\MonthReconciliation;
use App\Domain\TimeTracking\MonthLockService;
use App\Domain\TimeTracking\TimeEntryService;
use App\Livewire\Reports\ReconciliationReport;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverrideChange;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  list<Task>  $tasks
 * @param  list<User>  $users
 */
function reconProject(Client $client, string $name, array $tasks, array $users): Project
{
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => $name]);

    foreach ($tasks as $task) {
        $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null, 'rate_id' => null]);
    }
    foreach ($users as $user) {
        $project->users()->attach($user->id, ['hourly_rate_override' => null, 'rate_id' => null]);
    }

    return $project;
}

function reconEntry(Project $project, Task $task, User $user, string $spentOn, float $hours): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $spentOn,
        'hours' => $hours,
    ]);
}

function reconLock(Client $client, User $admin, string $month = '2026-07-01', string $issuedOn = '2026-08-03'): MonthLock
{
    return app(MonthLockService::class)->lock(
        $client,
        CarbonImmutable::parse($month),
        CarbonImmutable::parse($issuedOn),
        $admin,
    );
}

/** Opens an override for the admin and signs them in, as the Month locks page does. */
function reconOverride(MonthLock $lock, User $admin, string $reason = 'Correcting a mis-booked day'): void
{
    app(MonthLockService::class)->openOverride($lock, $admin, $reason);
    test()->actingAs($admin);
}

function reconJuly(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-01');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00'));

    $this->admin = User::factory()->admin()->create(['name' => 'Alex Admin']);
    $this->worker = User::factory()->create();
    $this->design = Task::factory()->create(['name' => 'Design']);
    $this->build = Task::factory()->create(['name' => 'Build']);
    $this->client = Client::factory()->create(['name' => 'JDW Projects']);
    $this->project = reconProject($this->client, 'Website build', [$this->design, $this->build], [$this->admin, $this->worker]);
});

it('shows issued and current hours with the difference after an override edit', function () {
    $edited = reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 2.0);
    reconEntry($this->project, $this->design, $this->worker, '2026-07-07', 1.5);
    $lock = reconLock($this->client, $this->admin);
    reconOverride($lock, $this->admin);

    app(TimeEntryService::class)->update($edited, ['hours' => 3.0]);

    $rows = MonthReconciliation::rows(reconJuly());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->clientName)->toBe('JDW Projects')
        ->and($rows[0]->projectName)->toBe('Website build')
        ->and($rows[0]->taskName)->toBe('Design')
        ->and($rows[0]->issuedHours)->toBe(3.5)
        ->and($rows[0]->currentHours)->toBe(4.5)
        ->and($rows[0]->difference)->toBe(1.0);

    $clients = Livewire::test(ReconciliationReport::class)->viewData('clients');

    expect($clients)->toHaveCount(1)
        ->and($clients[0]['issued'])->toBe(3.5)
        ->and($clients[0]['current'])->toBe(4.5)
        ->and($clients[0]['difference'])->toBe(1.0);
});

it('lists combinations added after the report was issued with zero issued hours', function () {
    reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 2.0);
    $lock = reconLock($this->client, $this->admin);
    reconOverride($lock, $this->admin);

    app(TimeEntryService::class)->create($this->worker, [
        'project_id' => $this->project->id,
        'task_id' => $this->build->id,
        'spent_on' => '2026-07-20',
        'hours' => 1.25,
        'notes' => null,
    ]);

    $rows = MonthReconciliation::rows(reconJuly());

    expect($rows->pluck('taskName')->all())->toBe(['Build', 'Design'])
        ->and($rows[0]->issuedHours)->toBe(0.0)
        ->and($rows[0]->currentHours)->toBe(1.25)
        ->and($rows[0]->difference)->toBe(1.25)
        ->and($rows[1]->difference)->toBe(0.0);
});

it('lists combinations whose entries were removed with zero current hours', function () {
    $removed = reconEntry($this->project, $this->build, $this->worker, '2026-07-06', 2.0);
    reconEntry($this->project, $this->design, $this->worker, '2026-07-07', 1.0);
    $lock = reconLock($this->client, $this->admin);
    reconOverride($lock, $this->admin);

    app(TimeEntryService::class)->delete($removed);

    $rows = MonthReconciliation::rows(reconJuly());

    expect($rows->pluck('taskName')->all())->toBe(['Build', 'Design'])
        ->and($rows[0]->issuedHours)->toBe(2.0)
        ->and($rows[0]->currentHours)->toBe(0.0)
        ->and($rows[0]->difference)->toBe(-2.0);
});

it('covers every client locked for the month, sorted by client, project and task', function () {
    $alpha = Client::factory()->create(['name' => 'Alpha Ltd']);
    $unlocked = Client::factory()->create(['name' => 'Unlocked Co']);
    $tasks = [$this->design, $this->build];
    $users = [$this->worker];

    $alphaBeta = reconProject($alpha, 'Beta site', $tasks, $users);
    $alphaLower = reconProject($alpha, 'alpha microsite', $tasks, $users);
    $unlockedProject = reconProject($unlocked, 'Hidden', $tasks, $users);

    reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 1.0);
    reconEntry($alphaBeta, $this->design, $this->worker, '2026-07-06', 1.0);
    reconEntry($alphaBeta, $this->build, $this->worker, '2026-07-06', 1.0);
    reconEntry($alphaLower, $this->design, $this->worker, '2026-07-06', 1.0);
    reconEntry($unlockedProject, $this->design, $this->worker, '2026-07-06', 1.0);
    // Another month of a locked client is a different report.
    reconEntry($alphaBeta, $this->design, $this->worker, '2026-06-15', 8.0);

    reconLock($this->client, $this->admin);
    reconLock($alpha, $this->admin);
    reconLock($alpha, $this->admin, '2026-06-01', '2026-07-03');

    $rows = MonthReconciliation::rows(reconJuly());

    expect($rows->map(fn ($row) => "{$row->clientName} / {$row->projectName} / {$row->taskName}")->all())->toBe([
        'Alpha Ltd / alpha microsite / Design',
        'Alpha Ltd / Beta site / Build',
        'Alpha Ltd / Beta site / Design',
        'JDW Projects / Website build / Design',
    ]);
});

it('returns no rows or changes for a month that has no locks', function () {
    reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 1.0);

    expect(MonthReconciliation::rows(reconJuly()))->toBeEmpty()
        ->and(MonthReconciliation::changes(reconJuly()))->toBeEmpty();
});

it('adds up subtotals per project and per client', function () {
    $retainer = reconProject($this->client, 'Retainer', [$this->design, $this->build], [$this->admin, $this->worker]);
    $web = reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 3.5);
    reconEntry($this->project, $this->build, $this->worker, '2026-07-07', 2.0);
    $support = reconEntry($retainer, $this->design, $this->worker, '2026-07-08', 1.0);
    $lock = reconLock($this->client, $this->admin);
    reconOverride($lock, $this->admin);

    app(TimeEntryService::class)->update($web, ['hours' => 4.5]);
    app(TimeEntryService::class)->update($support, ['hours' => 0.5]);

    $clients = Livewire::test(ReconciliationReport::class)->viewData('clients');
    $projects = $clients[0]['projects'];

    expect($clients)->toHaveCount(1)
        ->and([$clients[0]['issued'], $clients[0]['current'], $clients[0]['difference']])->toBe([6.5, 7.0, 0.5])
        ->and($projects)->toHaveCount(2)
        ->and($projects[0]['name'])->toBe('Retainer')
        ->and([$projects[0]['issued'], $projects[0]['current'], $projects[0]['difference']])->toBe([1.0, 0.5, -0.5])
        ->and($projects[1]['name'])->toBe('Website build')
        ->and([$projects[1]['issued'], $projects[1]['current'], $projects[1]['difference']])->toBe([5.5, 6.5, 1.0])
        ->and($projects[1]['rows'])->toHaveCount(2);
});

it('highlights only the rows whose hours differ by a hundredth of an hour or more', function () {
    $edited = reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 2.0);
    reconEntry($this->project, $this->build, $this->worker, '2026-07-07', 2.0);
    $lock = reconLock($this->client, $this->admin);

    $matching = Livewire::test(ReconciliationReport::class)->html();
    expect($matching)->not->toContain('bg-amber-50');

    reconOverride($lock, $this->admin);
    app(TimeEntryService::class)->update($edited, ['hours' => 2.01]);

    // The Design row plus its project and client subtotals; Build stays plain.
    $html = Livewire::test(ReconciliationReport::class)->html();
    expect(substr_count($html, 'bg-amber-50'))->toBe(3);
});

it('lists override changes with who, when and why', function () {
    $edited = reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 2.0);
    $lock = reconLock($this->client, $this->admin);
    reconOverride($lock, $this->admin, 'Moving time to the right task');

    app(TimeEntryService::class)->update($edited, ['hours' => 3.0]);

    Livewire::test(ReconciliationReport::class)
        ->assertSee('Alex Admin')
        ->assertSee('Moving time to the right task')
        ->assertSee('23 Oct 2026 10:00')
        ->assertSee('#'.$edited->id)
        ->assertSee('Updated')
        ->assertSee('2.00 → 3.00');
});

it('shows a dash for the side of the change that does not exist', function () {
    $removed = reconEntry($this->project, $this->build, $this->worker, '2026-07-06', 1.5);
    $lock = reconLock($this->client, $this->admin);
    reconOverride($lock, $this->admin);

    app(TimeEntryService::class)->create($this->worker, [
        'project_id' => $this->project->id,
        'task_id' => $this->design->id,
        'spent_on' => '2026-07-09',
        'hours' => 1.0,
        'notes' => null,
    ]);
    app(TimeEntryService::class)->delete($removed);

    Livewire::test(ReconciliationReport::class)
        ->assertSee('Created')
        ->assertSee('— → 1.00')
        ->assertSee('Deleted')
        ->assertSee('1.50 → —');
});

it('returns the month\'s override changes newest first with the override and user loaded', function () {
    $first = reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 2.0);
    $second = reconEntry($this->project, $this->build, $this->worker, '2026-07-07', 2.0);
    $june = reconEntry($this->project, $this->build, $this->worker, '2026-06-07', 2.0);
    $julyLock = reconLock($this->client, $this->admin);
    $juneLock = reconLock($this->client, $this->admin, '2026-06-01', '2026-07-03');

    reconOverride($julyLock, $this->admin);
    app(TimeEntryService::class)->update($first, ['hours' => 3.0]);
    $this->travel(5)->minutes();
    app(TimeEntryService::class)->update($second, ['hours' => 3.0]);

    reconOverride($juneLock, $this->admin, 'Fixing the June report');
    app(TimeEntryService::class)->update($june, ['hours' => 3.0]);

    $changes = MonthReconciliation::changes(reconJuly());

    expect($changes->pluck('time_entry_id')->all())->toBe([$second->id, $first->id])
        ->and($changes[0]->relationLoaded('override'))->toBeTrue()
        ->and($changes[0]->override->relationLoaded('user'))->toBeTrue()
        ->and($changes[0]->override->user->name)->toBe('Alex Admin')
        ->and(MonthLockOverrideChange::count())->toBe(3);
});

it('defaults to the newest locked month and offers only locked months', function () {
    reconEntry($this->project, $this->build, $this->worker, '2026-06-07', 2.0);
    reconEntry($this->project, $this->design, $this->worker, '2026-07-07', 2.0);
    reconLock($this->client, $this->admin, '2026-06-01', '2026-07-03');
    reconLock($this->client, $this->admin);

    $component = Livewire::test(ReconciliationReport::class)
        ->assertSet('month', '2026-07')
        ->assertSee('Design')
        ->assertDontSee('Build');

    expect($component->viewData('months'))->toBe(['2026-07' => 'July 2026', '2026-06' => 'June 2026']);

    $component->set('month', '2026-06')
        ->assertSee('Build')
        ->assertDontSee('Design');
});

it('reads the month from the URL and falls back to the newest locked month', function () {
    reconEntry($this->project, $this->build, $this->worker, '2026-06-07', 2.0);
    reconEntry($this->project, $this->design, $this->worker, '2026-07-07', 2.0);
    reconLock($this->client, $this->admin, '2026-06-01', '2026-07-03');
    reconLock($this->client, $this->admin);

    Livewire::withQueryParams(['month' => '2026-06'])
        ->test(ReconciliationReport::class)
        ->assertSet('month', '2026-06')
        ->assertSee('Build');

    foreach (['2026-01', 'not-a-month'] as $unusable) {
        Livewire::withQueryParams(['month' => $unusable])
            ->test(ReconciliationReport::class)
            ->assertSet('month', '2026-07')
            ->assertSee('Design');
    }
});

it('shows an empty state when no month has been locked', function () {
    $this->actingAs($this->admin)
        ->get('/reports/reconciliation')
        ->assertOk()
        ->assertSee('No months have been locked yet.');
});

it('is forbidden to standard users and open to managers and admins', function () {
    reconEntry($this->project, $this->design, $this->worker, '2026-07-06', 2.0);
    reconLock($this->client, $this->admin);

    $this->actingAs(User::factory()->create())->get('/reports/reconciliation')->assertForbidden();

    $this->actingAs(User::factory()->manager()->create())
        ->get('/reports/reconciliation')
        ->assertOk()
        ->assertSee('Reconciliation')
        ->assertSee('JDW Projects')
        ->assertSee('href="'.route('reports.reconciliation').'"', false);

    $this->actingAs($this->admin)->get('/reports/reconciliation')->assertOk();
});

it('links each lock on the Month locks page to its reconciliation', function () {
    reconLock($this->client, $this->admin);

    $this->actingAs($this->admin)
        ->get('/admin/month-locks')
        ->assertOk()
        ->assertSee('Reconciliation')
        ->assertSee('href="'.route('reports.reconciliation', ['month' => '2026-07']).'"', false);
});
