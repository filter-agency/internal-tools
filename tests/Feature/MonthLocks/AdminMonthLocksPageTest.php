<?php

use App\Enums\ClientTaskBillabilityProfile;
use App\Livewire\Admin\MonthLocks\Index;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverride;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const ADMIN_LOCKS_REASON = 'Correcting misfiled support hours';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00'));
});

function adminLocksJdwClient(array $attrs = []): Client
{
    return Client::factory()->create(array_merge([
        'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
    ], $attrs));
}

function adminLocksLockedJuly(Client $client, User $admin): MonthLock
{
    return MonthLock::create([
        'client_id' => $client->id,
        'month' => '2026-07-01',
        'issued_on' => '2026-08-03',
        'locked_by_user_id' => $admin->id,
    ]);
}

it('is forbidden to standard users and managers', function (User $user) {
    $this->actingAs($user)->get('/admin/month-locks')->assertForbidden();
})->with([
    'standard user' => fn () => User::factory()->create(),
    'manager' => fn () => User::factory()->manager()->create(),
]);

it('is available to admins and linked from the admin menu', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/month-locks')
        ->assertOk()
        ->assertSee('Month locks')
        ->assertSee('href="'.url('/admin/month-locks').'"', false);
});

it('defaults to last month, today, and the active JDW clients', function () {
    $jdw = adminLocksJdwClient();
    adminLocksJdwClient(['is_archived' => true]);
    Client::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Index::class)
        ->assertSet('month', '2026-09')
        ->assertSet('issuedOn', '2026-10-23')
        ->assertSet('clientIds', [$jdw->id]);
});

it('defaults to the previous calendar month at the end of a long month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00'));

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Index::class)
        ->assertSet('month', '2026-09');
});

it('locks the chosen month for every ticked client', function () {
    $admin = User::factory()->admin()->create();
    $a = adminLocksJdwClient(['name' => 'Acme']);
    $b = adminLocksJdwClient(['name' => 'Bravo']);
    $untouched = adminLocksJdwClient(['name' => 'Charlie']);
    $project = Project::factory()->create(['client_id' => $a->id]);
    $task = Task::factory()->create();
    TimeEntry::factory()->create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-07-10',
        'hours' => 2.5,
    ]);

    $page = Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('month', '2026-07')
        ->set('issuedOn', '2026-08-03')
        ->set('clientIds', [$a->id, $b->id])
        ->call('lock')
        ->assertHasNoErrors()
        ->assertSee('Locked July 2026 for 2 clients.');

    expect(MonthLock::count())->toBe(2);
    expect(MonthLock::where('client_id', $untouched->id)->exists())->toBeFalse();

    $lock = MonthLock::where('client_id', $a->id)->firstOrFail();
    expect($lock->month->toDateString())->toBe('2026-07-01');
    expect($lock->issued_on->toDateString())->toBe('2026-08-03');
    expect($lock->locked_by_user_id)->toBe($admin->id);

    $page->assertSeeInOrder(['Acme', 'July 2026', '2.50', $admin->name])
        ->assertSeeInOrder(['Bravo', 'July 2026', '0.00']);
});

it('lists each lock in its own row', function () {
    $admin = User::factory()->admin()->create();
    adminLocksLockedJuly(adminLocksJdwClient(), $admin);
    adminLocksLockedJuly(adminLocksJdwClient(), $admin);

    $html = Livewire::actingAs($admin)->test(Index::class)->html();

    expect(substr_count($html, 'July 2026'))->toBe(2);
});

it('locks nothing when one of the ticked clients is already locked', function () {
    $admin = User::factory()->admin()->create();
    // Clients are locked in name order, so the fresh one is locked before the failure.
    $fresh = adminLocksJdwClient(['name' => 'Acme']);
    $already = adminLocksJdwClient(['name' => 'Zulu']);
    adminLocksLockedJuly($already, $admin);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('month', '2026-07')
        ->set('clientIds', [$fresh->id, $already->id])
        ->call('lock')
        ->assertHasErrors(['month']);

    expect(MonthLock::count())->toBe(1);
    expect(MonthLock::where('client_id', $fresh->id)->exists())->toBeFalse();
});

it('keeps validation errors on the form', function (array $set, string $errorKey) {
    $client = adminLocksJdwClient();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Index::class)
        ->set(array_merge(['month' => '2026-07', 'clientIds' => [$client->id]], $set))
        ->call('lock')
        ->assertHasErrors([$errorKey]);

    expect(MonthLock::count())->toBe(0);
})->with([
    'month not ended' => [['month' => '2026-10'], 'month'],
    'issued in the future' => [['issuedOn' => '2026-10-24'], 'issued_on'],
    'no issued date' => [['issuedOn' => ''], 'issued_on'],
    'no month' => [['month' => ''], 'month'],
    'no clients' => [['clientIds' => []], 'clientIds'],
]);

it('opens an override with a reason and closes it again', function () {
    $admin = User::factory()->admin()->create();
    $lock = adminLocksLockedJuly(adminLocksJdwClient(['name' => 'Acme']), $admin);

    $page = Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('startOverride', $lock->id)
        ->assertSet('overrideLockId', $lock->id)
        ->set('overrideReason', ADMIN_LOCKS_REASON)
        ->call('openOverride')
        ->assertHasNoErrors()
        ->assertSet('overrideReason', '')
        ->assertSet('overrideLockId', null);

    $override = MonthLockOverride::firstOrFail();
    expect($override->month_lock_id)->toBe($lock->id);
    expect($override->user_id)->toBe($admin->id);
    expect($override->reason)->toBe(ADMIN_LOCKS_REASON);
    expect($override->isActive())->toBeTrue();
    expect($override->expires_at->equalTo(now()->addMinutes(60)))->toBeTrue();

    $page->assertSee($admin->name)->assertSee('Close override');

    $page->call('closeOverride', $override->id)->assertHasNoErrors();

    expect($override->fresh()->closed_at)->not->toBeNull();
    expect($override->fresh()->isActive())->toBeFalse();
    $page->assertDontSee('Close override');
});

it('rejects a short override reason', function () {
    $admin = User::factory()->admin()->create();
    $lock = adminLocksLockedJuly(adminLocksJdwClient(), $admin);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('startOverride', $lock->id)
        ->set('overrideReason', 'too short')
        ->call('openOverride')
        ->assertHasErrors(['reason'])
        ->assertSet('overrideLockId', $lock->id)
        ->assertSet('overrideReason', 'too short');

    expect(MonthLockOverride::count())->toBe(0);
});

it('refuses mutations once the viewer is no longer an admin', function (string $action) {
    $admin = User::factory()->admin()->create();
    $client = adminLocksJdwClient();
    $lock = adminLocksLockedJuly($client, $admin);
    $override = $lock->overrides()->create([
        'user_id' => $admin->id,
        'reason' => ADMIN_LOCKS_REASON,
        'expires_at' => now()->addMinutes(60),
    ]);

    $page = Livewire::actingAs($admin)->test(Index::class)->set('clientIds', [$client->id]);

    $this->actingAs(User::factory()->create());

    match ($action) {
        'lock' => $page->set('month', '2026-06')->call('lock'),
        'startOverride' => $page->call('startOverride', $lock->id),
        'closeOverride' => $page->call('closeOverride', $override->id),
    };

    $page->assertForbidden();
    expect(MonthLock::count())->toBe(1);
    expect($override->fresh()->closed_at)->toBeNull();
})->with(['lock', 'startOverride', 'closeOverride']);
