<?php

use App\Domain\Mcp\InternalMcpActions;
use App\Domain\TimeTracking\MonthLockService;
use App\Domain\TimeTracking\TimeEntryService;
use App\Http\Controllers\Api\TimersController;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaTokenManager;
use App\Services\TimesheetCompletionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00'));
});

/** @return array{User, Project, Task} */
function auditAssignedContext(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task, ['is_billable' => true]);
    $project->users()->attach($user, ['hourly_rate_override' => 84]);

    return [$user, $project, $task];
}

/** @return array{project_id: int, task_id: int, spent_on: string, hours: float, notes: string} */
function auditEntryData(Project $project, Task $task): array
{
    return ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => '2026-11-09', 'hours' => 2.0, 'notes' => 'Original note'];
}

it('rejects a day-view request for an unassigned project', function () {
    [$user, $project, $task] = auditAssignedContext();
    $project->users()->detach($user);
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $task->id)
        ->set('hoursInput', '1:00')
        ->call('save')
        ->assertForbidden();

    expect(TimeEntry::count())->toBe(0);
});

it('rejects a task that is not assigned to the selected project', function () {
    [$user, $project] = auditAssignedContext();
    $otherTask = Task::factory()->create();
    $this->actingAs($user);

    Livewire::test(DayView::class)
        ->set('selectedProjectId', $project->id)
        ->set('selectedTaskId', $otherTask->id)
        ->set('hoursInput', '1:00')
        ->call('save')
        ->assertHasErrors('task_id');

    expect(TimeEntry::count())->toBe(0);
});

it('rejects forged weekly row destinations at the service boundary', function () {
    [$user, $project, $task] = auditAssignedContext();
    $project->users()->detach($user);
    $this->actingAs($user);
    $week = new WeekView;
    $week->selectedDate = '2026-11-09';
    $week->cellValues = ["p{$project->id}_t{$task->id}_anone" => ['1:00']];

    expect(fn () => $week->save())->toThrow(AuthorizationException::class)
        ->and(TimeEntry::count())->toBe(0);
});

it('allows an admin to correct time on an unassigned project', function () {
    [$user, $project, $task] = auditAssignedContext();
    $project->users()->detach($user);
    $this->actingAs(User::factory()->admin()->create());

    $entry = app(TimeEntryService::class)->create($user, auditEntryData($project, $task));

    expect($entry->user_id)->toBe($user->id);
});

it('logs out an archived browser user and invalidates the session', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    $this->withSession(['audit_marker' => 'old session']);
    $user->archive();

    $this->get('/admin/users')->assertRedirect(route('auth.login'));
    $this->assertGuest();
    expect(session()->has('audit_marker'))->toBeFalse();
});

it('rejects an inactive Livewire session before an action runs', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->actingAs($user);

    $this->postJson(route('default-livewire.update'), [])->assertUnauthorized();
    $this->assertGuest();
});

it('keeps active browser and personal-token users working', function () {
    [$user] = auditAssignedContext();
    $this->actingAs($user)->get('/timesheet')->assertOk();
    $token = PersonalAccessToken::generate($user, 'Audit regression')['token'];
    $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id);
});

it('preserves saved billing when notes or hours change after a rate change', function () {
    [$user, $project, $task] = auditAssignedContext();
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, auditEntryData($project, $task));
    $project->users()->updateExistingPivot($user->id, ['hourly_rate_override' => 120]);

    $service->update($entry, ['notes' => 'Corrected note']);
    expect((float) $entry->billable_rate_snapshot)->toBe(84.0)
        ->and((float) $entry->billable_amount)->toBe(168.0);

    $service->update($entry, ['hours' => 3.0]);
    expect((float) $entry->billable_amount)->toBe(252.0);
});

it('resolves new billing when an entry moves to a different project', function () {
    [$user, $project, $task] = auditAssignedContext();
    $destination = Project::factory()->create();
    $destination->users()->attach($user, ['hourly_rate_override' => 120]);
    $destination->tasks()->attach($task, ['is_billable' => true]);
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, auditEntryData($project, $task));

    $service->update($entry, ['project_id' => $destination->id]);
    expect((float) $entry->billable_amount)->toBe(240.0);
});

it('does not reprice non-billable historical entries after project settings change', function () {
    [$user, $project, $task] = auditAssignedContext();
    $project->update(['is_billable' => false]);
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, auditEntryData($project, $task));
    $project->update(['is_billable' => true]);

    $service->update($entry, ['notes' => 'Still non-billable']);
    expect($entry->is_billable)->toBeFalse()->and((float) $entry->billable_amount)->toBe(0.0);
});

it('ignores a stale stop request after the timer was stopped and corrected', function () {
    [$user, $project, $task] = auditAssignedContext();
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [...auditEntryData($project, $task), 'hours' => 0]);
    $entry->update(['is_running' => true, 'timer_started_at' => now()->subHour()]);
    $stale = TimeEntry::findOrFail($entry->id);
    $service->stopTimer($entry);
    $service->update($entry, ['hours' => 2.0]);

    $service->stopTimer($stale);
    expect((float) $entry->fresh()->hours)->toBe(2.0)->and($stale->is_running)->toBeFalse();
});

it('stops timers using their saved rate after the current rate changes', function () {
    [$user, $project, $task] = auditAssignedContext();
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [...auditEntryData($project, $task), 'hours' => 0]);
    $entry->update(['is_running' => true, 'timer_started_at' => now()->subHour()]);
    $project->users()->updateExistingPivot($user->id, ['hourly_rate_override' => 120]);

    $service->stopTimer($entry);
    expect((float) $entry->billable_amount)->toBe(84.0);
});

it('keeps Asana refresh credentials after transient or client configuration errors', function (int $status, string $error) {
    $user = User::factory()->create([
        'asana_access_token' => 'old-access', 'asana_refresh_token' => 'valid-refresh',
        'asana_user_gid' => '123', 'asana_token_expires_at' => now()->subMinute(),
    ]);
    Http::fake(['app.asana.com/-/oauth_token' => Http::response(['error' => $error], $status)]);

    expect(app(AsanaTokenManager::class)->getValidToken($user))->toBeNull();
    $user->refresh();
    expect($user->asana_refresh_token)->toBe('valid-refresh')
        ->and($user->asana_user_gid)->toBe('123')
        ->and($user->asana_connection_lost_at)->toBeNull();
})->with([[503, 'server_error'], [429, 'rate_limit'], [401, 'invalid_client']]);

it('validates the entire week before saving any changed cells', function () {
    [$user, $project, $task] = auditAssignedContext();
    $this->actingAs($user);
    $entry = app(TimeEntryService::class)->create($user, [...auditEntryData($project, $task), 'hours' => 1.0]);
    $key = "p{$project->id}_t{$task->id}_anone";

    $component = Livewire::test(WeekView::class)
        ->set("cellValues.{$key}.0", '2:00')
        ->set("cellValues.{$key}.1", '25:00')
        ->call('save')
        ->assertHasErrors("cellValues.{$key}.1")
        ->assertSee('Hours must be between')
        ->assertDontSee('Saved');

    expect((float) $entry->fresh()->hours)->toBe(1.0)->and(TimeEntry::count())->toBe(1);

    $component->set("cellValues.{$key}.1", '3:00')->call('save')->assertHasNoErrors()->assertSee('Saved');
    expect((float) TimeEntry::sum('hours'))->toBe(5.0);
});

it('uses the configured work pattern for both monthly targets and overdue selection', function () {
    $user = User::factory()->create(['weekly_capacity_hours' => 32, 'schedule_work_days' => [1, 2, 3, 4]]);
    [$other, $project, $task] = auditAssignedContext();
    $project->users()->attach($user);
    foreach (range(1, 17) as $day) {
        TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => sprintf('2026-10-%02d', $day), 'hours' => 8]);
    }
    $service = app(TimesheetCompletionService::class);
    $month = CarbonImmutable::parse('2026-10-01');

    expect($service->monthlyTarget($user, $month))->toBe(136.0)
        ->and($service->usersWithIncompleteMonth($month)->pluck('user.id')->all())->not->toContain($user->id);
});

it('refuses stale writes after an entry moves into another client locked month', function (string $operation) {
    [$user, $project, $task] = auditAssignedContext();
    $this->actingAs($user);
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [...auditEntryData($project, $task), 'spent_on' => '2026-10-09']);
    $stale = $entry->fresh();
    $destination = Project::factory()->create();
    $destination->users()->attach($user);
    $destination->tasks()->attach($task, ['is_billable' => true]);
    $service->update($entry, ['project_id' => $destination->id]);
    $lock = app(MonthLockService::class)->lock(
        $destination->client, CarbonImmutable::parse('2026-10-01'),
        CarbonImmutable::today(), User::factory()->admin()->create(),
    );
    $before = $entry->fresh()->getAttributes();

    $write = match ($operation) {
        'service update' => fn () => $service->update($stale, ['notes' => 'Stale overwrite']),
        'service delete' => fn () => $service->delete($stale),
        'model update' => fn () => $stale->update(['notes' => 'Stale overwrite']),
        'model delete' => fn () => $stale->delete(),
    };

    expect($write)->toThrow(ValidationException::class)
        ->and($entry->fresh()->getAttributes())->toBe($before)
        ->and((float) $lock->snapshots()->sum('hours'))->toBe(2.0);
})->with(['service update', 'service delete', 'model update', 'model delete']);

it('preserves the saved fractional billing amount on a notes-only edit', function () {
    [$user, $project, $task] = auditAssignedContext();
    $project->users()->updateExistingPivot($user->id, ['hourly_rate_override' => 100]);
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, [...auditEntryData($project, $task), 'hours' => 0.3333])->fresh();
    expect((float) $entry->hours)->toBe(0.33)->and((float) $entry->billable_amount)->toBe(33.33);

    $service->update($entry, ['notes' => 'Corrected note']);
    expect((float) $entry->billable_amount)->toBe(33.33);

    $service->update($entry, ['hours' => 0.33, 'notes' => 'Same saved hours']);
    expect((float) $entry->billable_amount)->toBe(33.33);

    $service->update($entry, ['hours' => 0.5]);
    expect((float) $entry->billable_amount)->toBe(50.0);
});

it('returns no timer when a stale stop targets a deleted entry', function () {
    [$user, $project, $task] = auditAssignedContext();
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, auditEntryData($project, $task));
    $service->startTimer($entry);
    $stale = $entry->fresh();
    $entry->delete();

    expect($service->stopTimer($stale))->toBeNull()
        ->and($stale->exists)->toBeFalse()
        ->and(TimeEntry::count())->toBe(0);
});

it('handles a timer deleted between caller lookup and the stop operation', function (string $caller) {
    [$user, $project, $task] = auditAssignedContext();
    $service = app(TimeEntryService::class);
    $entry = $service->create($user, auditEntryData($project, $task));
    $service->startTimer($entry);
    $deleted = false;
    DB::listen(function (QueryExecuted $query) use ($entry, &$deleted): void {
        if (! $deleted && str_starts_with($query->sql, 'select * from "time_entries"') && str_contains($query->sql, '"is_running" =')) {
            $deleted = true;
            DB::table('time_entries')->where('id', $entry->id)->delete();
        }
    });

    if ($caller === 'API') {
        $request = Request::create('/api/timers/stop', 'POST');
        $request->setUserResolver(fn () => $user);
        $response = app(TimersController::class)->stop($request, $service);
        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true))->toBe(['error' => 'no_running_timer']);
    } else {
        expect(fn () => app(InternalMcpActions::class)->stopTimer($user))->toThrow(ValidationException::class);
    }

    expect($deleted)->toBeTrue()->and(TimeEntry::count())->toBe(0);
})->with(['API', 'MCP']);
