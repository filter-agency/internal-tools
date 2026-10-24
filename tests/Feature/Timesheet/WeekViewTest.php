<?php

use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\Role;
use App\Livewire\Timesheet\WeekView;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function weekViewSetup(): array
{
    $user = User::factory()->create(['role' => Role::User, 'default_hourly_rate' => 100]);
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task->id, ['is_billable' => true, 'hourly_rate_override' => null]);
    $project->users()->attach($user->id, ['hourly_rate_override' => null]);

    return [$user, $project, $task];
}

function weekViewRowKey(Project $project, Task $task, ?string $asanaGid = null): string
{
    return 'p'.$project->id.'_t'.$task->id.'_a'.($asanaGid ?? 'none');
}

test('week view sums same-day entries that only differ by notes', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 1.0,
        'notes' => 'First note',
    ]);
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 0.5,
        'notes' => 'Second note',
    ]);

    $rowKey = weekViewRowKey($project, $task);
    $component = Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '1:30');

    expect($component->viewData('dayTotals')[0])->toBe(1.5)
        ->and($component->viewData('weekTotal'))->toBe(1.5);
});

test('saving an unchanged summed week cell keeps the existing total', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 1.0,
        'notes' => 'First note',
    ]);
    app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 0.5,
        'notes' => 'Second note',
    ]);

    $rowKey = weekViewRowKey($project, $task);

    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '1:30')
        ->call('save');

    expect((float) TimeEntry::whereDate('spent_on', $monday)->sum('hours'))->toBe(1.5);
});

test('saving the week leaves an untouched cell whose h:mm value does not round-trip', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    // 1.09h shows as 1:05, which parses back as 1.08h.
    $monday = now()->startOfWeek()->toDateString();
    $wednesday = now()->startOfWeek()->addDays(2)->toDateString();
    $entry = app(TimeEntryService::class)->create($user, [
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $monday,
        'hours' => 1.09,
        'notes' => null,
    ]);

    $rowKey = weekViewRowKey($project, $task);
    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '1:05')
        ->set("cellValues.{$rowKey}.2", '2:00')
        ->call('save');

    expect((float) $entry->fresh()->hours)->toBe(1.09)
        ->and((float) TimeEntry::whereDate('spent_on', $wednesday)->sole()->hours)->toBe(2.0);
});

test('save creates new entries from filled cells', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $rowKey = weekViewRowKey($project, $task);

    Livewire::test(WeekView::class)
        ->set('extraRows', [$rowKey])
        ->set("cellValues.{$rowKey}", ['2:00', '', '3:00', '', '', '', ''])
        ->call('save');

    $monday = now()->startOfWeek()->toDateString();
    $wednesday = now()->startOfWeek()->addDays(2)->toDateString();
    expect(TimeEntry::whereDate('spent_on', $monday)->where('user_id', $user->id)->first()->hours)->toBe('2.00');
    expect(TimeEntry::whereDate('spent_on', $wednesday)->where('user_id', $user->id)->first()->hours)->toBe('3.00');

    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}.0", '2:00')
        ->assertSet("cellValues.{$rowKey}.2", '3:00');
});

test('save deletes entries when their cell is cleared', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $monday = now()->startOfWeek()->toDateString();
    app(TimeEntryService::class)->create($user, ['project_id' => $project->id, 'task_id' => $task->id, 'spent_on' => $monday, 'hours' => 1.0, 'notes' => null]);

    expect(TimeEntry::count())->toBe(1);

    $rowKey = weekViewRowKey($project, $task);
    Livewire::test(WeekView::class)
        ->set("cellValues.{$rowKey}", ['', '', '', '', '', '', ''])
        ->call('save');

    expect(TimeEntry::count())->toBe(0);
});

test('empty rows persist after the session is gone (returning the next day)', function () {
    [$user, $project, $task] = weekViewSetup();
    $this->actingAs($user);

    $rowKey = weekViewRowKey($project, $task);

    // Add a row with no time logged against it, and confirm it shows up.
    Livewire::test(WeekView::class)
        ->set('newRowProjectId', $project->id)
        ->set('newRowTaskId', $task->id)
        ->call('addRow')
        ->assertSet("cellValues.{$rowKey}", ['', '', '', '', '', '', '']);

    // Simulate returning the next day: the previous session has expired, so
    // any state that only lived in the session is gone.
    session()->flush();

    // The empty row must still be present on a fresh mount.
    Livewire::test(WeekView::class)
        ->assertSet("cellValues.{$rowKey}", ['', '', '', '', '', '', '']);
});
