<?php

use App\Models\User;
use App\Notifications\BreatheSyncNeedsAttention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.breathe.api_key' => 'test-key',
        'services.breathe.base_url' => 'https://breathe.test/v1',
        'services.breathe.sync_alert_email' => 'paul@filteragency.com',
    ]);
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function breatheEmployee(int $id, string $email, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'first_name' => 'Person',
        'last_name' => (string) $id,
        'email' => $email,
        'join_date' => '2024-01-08',
        'leaving_date' => null,
        'person_type' => 'Employee',
        'status' => 'Current employee',
    ], $overrides);
}

/** @param  list<array<string, mixed>>  $employees */
function fakeBreathe(array $employees): void
{
    Http::fake(['breathe.test/*' => Http::response(['employees' => $employees])]);
}

test('copies dates and employment type from Breathe onto the user matched by email', function () {
    $person = User::factory()->create(['email' => 'Sam@Filter.agency', 'started_on' => '2020-01-01', 'is_contractor' => false]);
    fakeBreathe([breatheEmployee(41, 'sam@filter.agency', [
        'join_date' => '2023-05-02',
        'leaving_date' => '2026-11-30',
        'person_type' => 'Contractor',
    ])]);

    artisan('breathe:sync-employees')->assertSuccessful();

    $person->refresh();
    expect($person->breathe_employee_id)->toBe(41)
        ->and($person->started_on?->toDateString())->toBe('2023-05-02')
        ->and($person->left_on?->toDateString())->toBe('2026-11-30')
        ->and($person->is_contractor)->toBeTrue()
        ->and($person->breathe_synced_at)->not->toBeNull()
        ->and($person->isSyncedFromBreathe())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-API-KEY', 'test-key')
        && str_starts_with($request->url(), 'https://breathe.test/v1/employees'));
    Notification::assertNothingSent();
});

test('Breathe wins, so a cleared leaving date in Breathe clears the saved one', function () {
    $person = User::factory()->create(['email' => 'sam@filter.agency', 'breathe_employee_id' => 41, 'left_on' => '2026-06-30']);
    fakeBreathe([breatheEmployee(41, 'sam@filter.agency')]);

    artisan('breathe:sync-employees')->assertSuccessful();

    expect($person->fresh()->left_on)->toBeNull();
});

test('a linked user keeps syncing after their email changes', function () {
    $person = User::factory()->create(['email' => 'old@filter.agency', 'breathe_employee_id' => 41]);
    fakeBreathe([breatheEmployee(41, 'new@filter.agency', ['join_date' => '2022-02-01'])]);

    artisan('breathe:sync-employees')->assertSuccessful();

    expect($person->fresh()->started_on?->toDateString())->toBe('2022-02-01');
});

test('a rejoiner is matched to their latest Breathe record', function () {
    $person = User::factory()->create(['email' => 'sam@filter.agency']);
    fakeBreathe([
        breatheEmployee(10, 'sam@filter.agency', ['join_date' => '2019-03-01', 'leaving_date' => '2021-04-30']),
        breatheEmployee(55, 'sam@filter.agency', ['join_date' => '2024-09-02']),
    ]);

    artisan('breathe:sync-employees')->assertSuccessful();

    $person->refresh();
    expect($person->breathe_employee_id)->toBe(55)
        ->and($person->started_on?->toDateString())->toBe('2024-09-02')
        ->and($person->left_on)->toBeNull();
    Notification::assertNothingSent();
});

test('dry run writes nothing and sends no email', function () {
    $person = User::factory()->create(['email' => 'sam@filter.agency']);
    fakeBreathe([
        breatheEmployee(41, 'sam@filter.agency'),
        breatheEmployee(42, 'nobody@filter.agency'),
    ]);

    artisan('breathe:sync-employees --dry-run')
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('nobody@filter.agency')
        ->assertSuccessful();

    expect($person->fresh()->breathe_employee_id)->toBeNull();
    Notification::assertNothingSent();
});

test('emails once about current Breathe employees with no account, ignoring former staff', function () {
    fakeBreathe([
        breatheEmployee(42, 'new.starter@filter.agency'),
        breatheEmployee(43, 'long.gone@filter.agency', ['leaving_date' => '2020-01-31']),
    ]);

    artisan('breathe:sync-employees')->assertSuccessful();
    artisan('breathe:sync-employees')->assertSuccessful();

    Notification::assertSentOnDemandTimes(BreatheSyncNeedsAttention::class, 1);
    Notification::assertSentOnDemand(BreatheSyncNeedsAttention::class, function (BreatheSyncNeedsAttention $n, array $channels, object $notifiable) {
        return $notifiable->routes['mail'] === 'paul@filteragency.com'
            && $n->failure === null
            && count($n->unmatched) === 1
            && str_contains($n->unmatched[0], 'new.starter@filter.agency');
    });
});

test('a clean run lets the same issue alert again later', function () {
    fakeBreathe([breatheEmployee(42, 'new.starter@filter.agency')]);
    artisan('breathe:sync-employees')->assertSuccessful();

    User::factory()->create(['email' => 'new.starter@filter.agency']);
    artisan('breathe:sync-employees')->assertSuccessful();

    User::query()->delete();
    artisan('breathe:sync-employees')->assertSuccessful();

    Notification::assertSentOnDemandTimes(BreatheSyncNeedsAttention::class, 2);
});

test('skips and reports a record whose leaving date is before its join date', function () {
    $person = User::factory()->create(['email' => 'sam@filter.agency', 'started_on' => '2024-01-08']);
    fakeBreathe([breatheEmployee(41, 'sam@filter.agency', ['join_date' => '2025-01-01', 'leaving_date' => '2024-12-31'])]);

    artisan('breathe:sync-employees')->assertSuccessful();

    expect($person->fresh()->started_on?->toDateString())->toBe('2024-01-08')
        ->and($person->fresh()->breathe_employee_id)->toBeNull();
    Notification::assertSentOnDemand(BreatheSyncNeedsAttention::class, fn (BreatheSyncNeedsAttention $n) => str_contains($n->problems[0], 'before join date'));
});

test('leaves employment type alone for an unknown person type and reports it', function () {
    $person = User::factory()->create(['email' => 'sam@filter.agency', 'is_contractor' => true]);
    fakeBreathe([breatheEmployee(41, 'sam@filter.agency', ['person_type' => 'Volunteer'])]);

    artisan('breathe:sync-employees')->assertSuccessful();

    expect($person->fresh()->is_contractor)->toBeTrue()
        ->and($person->fresh()->breathe_employee_id)->toBe(41);
    Notification::assertSentOnDemand(BreatheSyncNeedsAttention::class, fn (BreatheSyncNeedsAttention $n) => str_contains($n->problems[0], 'Volunteer'));
});

test('reads every page of employees', function () {
    User::factory()->create(['email' => 'last@filter.agency']);
    $firstPage = array_map(fn (int $i) => breatheEmployee($i, "p{$i}@filter.agency", ['leaving_date' => '2020-01-31']), range(1, 100));
    Http::fake(['breathe.test/*' => Http::sequence()
        ->push(['employees' => $firstPage])
        ->push(['employees' => [breatheEmployee(101, 'last@filter.agency')]])]);

    artisan('breathe:sync-employees')->assertSuccessful();

    expect(User::query()->where('email', 'last@filter.agency')->value('breathe_employee_id'))->toBe(101);
    Http::assertSentCount(2);
});

test('an API failure writes nothing and emails without echoing the response body', function () {
    $person = User::factory()->create(['email' => 'sam@filter.agency', 'started_on' => '2024-01-08']);
    Http::fake(['breathe.test/*' => Http::response(['error' => 'secret detail'], 401)]);

    artisan('breathe:sync-employees')->assertFailed();

    expect($person->fresh()->started_on?->toDateString())->toBe('2024-01-08');
    Notification::assertSentOnDemand(BreatheSyncNeedsAttention::class, fn (BreatheSyncNeedsAttention $n) => $n->failure === 'Breathe HR answered HTTP 401.');
});

test('refuses to run without an API key', function () {
    config(['services.breathe.api_key' => null]);
    Http::fake();

    artisan('breathe:sync-employees')->assertFailed();

    Http::assertNothingSent();
    Notification::assertNothingSent();
});
