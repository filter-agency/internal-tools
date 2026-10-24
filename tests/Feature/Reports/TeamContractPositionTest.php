<?php

use App\Enums\ContractPosition;
use App\Livewire\Reports\TeamOverviewReport;
use App\Livewire\Reports\TeamReport;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserContractPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A user with one entry in July 2026 so they appear in the overview. */
function teamPositionUser(array $attributes = [], float $hours = 1.0): User
{
    $user = User::factory()->create($attributes);

    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'spent_on' => '2026-07-10',
        'hours' => $hours,
    ]);

    return $user;
}

function teamPositionSet(User $user, ?ContractPosition $position, string $effectiveFrom): void
{
    UserContractPosition::create([
        'user_id' => $user->id,
        'position' => $position,
        'effective_from' => $effectiveFrom,
    ]);
}

function teamPositionOverview(string $from = '2026-07-01', string $to = '2026-07-31')
{
    test()->actingAs(User::factory()->admin()->create());

    return Livewire::test(TeamOverviewReport::class)
        ->set('preset', 'custom')
        ->set('from', $from)
        ->set('to', $to);
}

function teamPositionMember(User $member, string $from = '2026-07-01', string $to = '2026-07-31')
{
    test()->actingAs(User::factory()->admin()->create());

    return Livewire::test(TeamReport::class, ['user' => $member])
        ->set('preset', 'custom')
        ->set('from', $from)
        ->set('to', $to);
}

test('team overview shows each member\'s contract position beside their name', function () {
    $senior = teamPositionUser(['name' => 'Alice Senior'], hours: 5);
    teamPositionSet($senior, ContractPosition::SeniorDeveloper, '2026-01-01');
    $outside = teamPositionUser(['name' => 'Bob Outside'], hours: 2);

    teamPositionOverview()
        ->assertSee('Contract position')
        ->assertSeeInOrder(['Alice Senior', 'Senior Developer', 'Bob Outside', 'Outside contracted team']);
});

test('team overview shows the position held at the end of the period', function () {
    $user = teamPositionUser(['name' => 'Cara Changer']);
    teamPositionSet($user, ContractPosition::Developer, '2026-01-01');
    teamPositionSet($user, ContractPosition::SeniorDeveloper, '2026-07-15');

    teamPositionOverview('2026-07-01', '2026-07-31')->assertSee('Senior Developer');
    teamPositionOverview('2026-07-01', '2026-07-10')
        ->assertSee('Developer')
        ->assertDontSee('Senior Developer');
});

test('team overview uses today\'s position when the period ends in the future', function () {
    $this->travelTo('2026-07-15');

    $user = teamPositionUser(['name' => 'Dan Future']);
    teamPositionSet($user, ContractPosition::Developer, '2026-01-01');
    teamPositionSet($user, ContractPosition::SeniorDeveloper, '2026-09-01');

    teamPositionOverview('2026-07-01', '2026-12-31')
        ->assertSee('Developer')
        ->assertDontSee('Senior Developer');
});

test('team member page shows the contract position', function () {
    $member = teamPositionUser(['name' => 'Eve Member']);
    teamPositionSet($member, ContractPosition::SeniorDeveloper, '2026-01-01');

    teamPositionMember($member)->assertSee('Senior Developer');
});

test('team member page shows outside contracted team when there is no position', function () {
    $member = teamPositionUser(['name' => 'Finn Nopos']);

    teamPositionMember($member)->assertSee('Outside contracted team');
});

test('team member page uses today\'s position when the period ends in the future', function () {
    $this->travelTo('2026-07-15');

    $member = teamPositionUser(['name' => 'Gus Future']);
    teamPositionSet($member, ContractPosition::Developer, '2026-01-01');
    teamPositionSet($member, ContractPosition::SeniorDeveloper, '2026-09-01');

    teamPositionMember($member, '2026-07-01', '2026-12-31')
        ->assertSee('Developer')
        ->assertDontSee('Senior Developer')
        ->assertSee('Position as at 15 Jul 2026');
});

test('team member page shows the start and leave dates when set', function () {
    $member = teamPositionUser([
        'name' => 'Hana Dates',
        'started_on' => '2025-03-03',
        'left_on' => '2026-06-30',
    ]);
    teamPositionSet($member, ContractPosition::SeniorDeveloper, '2025-03-03');

    teamPositionMember($member)
        ->assertSee('Senior Developer · Started 3 Mar 2025 · Left 30 Jun 2026');
});

test('team member page omits the start and leave dates when they are not set', function () {
    $member = teamPositionUser(['name' => 'Ivy Undated']);
    teamPositionSet($member, ContractPosition::Developer, '2026-01-01');

    teamPositionMember($member)
        ->assertSee('Developer')
        ->assertDontSee('Started')
        ->assertDontSee('Left');
});

test('team member page omits only the date that is not set', function () {
    $member = teamPositionUser(['name' => 'Jon Started', 'started_on' => '2025-03-03']);
    teamPositionSet($member, ContractPosition::Developer, '2025-03-03');

    teamPositionMember($member)
        ->assertSee('Developer · Started 3 Mar 2025')
        ->assertDontSee('Left');
});
