<?php

use App\Domain\TimeTracking\MonthLockService;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\MonthLockSnapshot;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires MySQL row locks and separate process connections.');
    }
    $this->artisan('migrate:fresh')->assertSuccessful();
});

it('serialises month closure with time-entry writes in either order', function (string $firstAction) {
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->tasks()->attach($task, ['is_billable' => true]);
    $project->users()->attach($user);
    $directory = sys_get_temp_dir().'/month-lock-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $release = $directory.'/release';
    $environment = ['APP_ENV' => 'testing', 'AUDIT_DATABASE_CONFIG' => json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR)];
    $worker = function (string $action, string $marker, bool $hold) use ($user, $admin, $project, $task, $release, $environment): Process {
        return new Process([
            PHP_BINARY, base_path('tests/Fixtures/month-lock-worker.php'), $action,
            (string) $user->id, (string) $project->id, (string) $task->id, (string) $admin->id,
            $marker, $release, $hold ? '1' : '0',
        ], base_path(), $environment, timeout: 15);
    };
    $awaitFile = function (string $path, Process $process): void {
        $deadline = microtime(true) + 8;
        while (! file_exists($path)) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Worker did not reach its barrier: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(10000);
        }
    };
    $first = $worker($firstAction, $directory.'/first', true);
    $second = $worker($firstAction === 'write' ? 'lock' : 'write', $directory.'/second', false);

    try {
        $first->start();
        $awaitFile($directory.'/first', $first);
        $second->start();
        $awaitFile($directory.'/second.started', $second);
        usleep(500000);
        expect($second->isRunning())->toBeTrue();
        file_put_contents($release, 'release');
        $first->wait();
        $second->wait();
        expect($first->getExitCode())->toBe(0)
            ->and($second->getExitCode())->toBe(0)
            ->and($first->getOutput())->toBe('committed')
            ->and($second->getOutput())->toBe($firstAction === 'write' ? 'committed' : 'refused');
        $expected = $firstAction === 'write' ? 1.0 : 0.0;
        expect((float) TimeEntry::sum('hours'))->toBe($expected)
            ->and((float) MonthLockSnapshot::sum('hours'))->toBe($expected);
    } finally {
        file_put_contents($release, 'release');
        $first->stop();
        $second->stop();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(['write', 'lock']);

it('serialises overlapping timer starts and stops across clients', function (bool $startFirst) {
    Queue::fake();
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $projects = Project::factory()->count(2)->create();
    foreach ($projects as $project) {
        $project->users()->attach($user);
        $project->tasks()->attach($task, ['is_billable' => true]);
    }
    $running = TimeEntry::factory()->create([
        'user_id' => $user->id, 'project_id' => $projects[0]->id, 'task_id' => $task->id,
        'hours' => 0, 'is_running' => true, 'timer_started_at' => now()->subHour(),
    ]);
    $next = TimeEntry::factory()->create([
        'user_id' => $user->id, 'project_id' => $projects[1]->id, 'task_id' => $task->id, 'hours' => 0,
    ]);
    $directory = sys_get_temp_dir().'/timer-lock-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $worker = new Process([
        PHP_BINARY, base_path('tests/Fixtures/month-lock-worker.php'), $startFirst ? 'timer-stop' : 'timer-start',
        (string) $user->id, (string) $projects[0]->id, (string) $task->id, (string) $user->id,
        $directory.'/worker', $directory.'/release', '0', (string) ($startFirst ? $running->id : $next->id),
    ], base_path(), [
        'APP_ENV' => 'testing',
        'AUDIT_DATABASE_CONFIG' => json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR),
    ], timeout: 15);
    $overlapped = false;
    DB::listen(function (QueryExecuted $query) use ($worker, $directory, &$overlapped): void {
        // Start the competing operation while the first holds its entry locks.
        if ($overlapped || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`time_entries`')
            || ! str_contains($query->sql, 'for update')
            || (! str_contains($query->sql, ' in (') && ! str_contains($query->sql, '`is_running`'))) {
            return;
        }
        $overlapped = true;
        $worker->start();
        $deadline = microtime(true) + 8;
        while (! file_exists($directory.'/worker.started')) {
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Timer worker did not start: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(10000);
        }
        usleep(300000);
        expect($worker->isRunning())->toBeTrue($worker->getOutput().$worker->getErrorOutput());
    });

    try {
        $service = app(TimeEntryService::class);
        if ($startFirst) {
            $service->startTimer($next);
        } else {
            $service->stopTimer($running);
        }
        expect($overlapped)->toBeTrue();
        $worker->wait();
        expect($worker->getExitCode())->toBe(0, $worker->getOutput().$worker->getErrorOutput())
            ->and($worker->getOutput())->toBe('committed')
            ->and($running->fresh()->is_running)->toBeFalse()
            ->and((float) $running->fresh()->hours)->toBe(1.0)
            ->and(TimeEntry::where('user_id', $user->id)->where('is_running', true)->pluck('id')->all())->toBe([$next->id]);
    } finally {
        $worker->stop();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(['start first' => true, 'stop first' => false]);

it('orders user foreign-key locks before client locks during concurrent timer operations', function (string $write, string $timerAction) {
    Queue::fake();
    $user = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $task = Task::factory()->create();
    $project->users()->attach($user);
    $project->tasks()->attach($task, ['is_billable' => true]);
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id, 'task_id' => $task->id,
        'is_running' => $timerAction === 'timer-stop',
        'timer_started_at' => $timerAction === 'timer-stop' ? now()->subHour() : null,
    ]);
    $directory = sys_get_temp_dir().'/timer-foreign-key-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $worker = new Process([
        PHP_BINARY, base_path('tests/Fixtures/month-lock-worker.php'), $timerAction,
        (string) $user->id, (string) $project->id, (string) $task->id, (string) $user->id,
        $directory.'/worker', $directory.'/release', '0', (string) $entry->id,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'AUDIT_DATABASE_CONFIG' => json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR),
    ], timeout: 15);
    $overlapped = false;
    DB::listen(function (QueryExecuted $query) use ($worker, $directory, &$overlapped): void {
        if ($overlapped || ! str_starts_with($query->sql, 'select')
            || ! str_contains($query->sql, '`clients`') || ! str_contains($query->sql, 'for update')) {
            return;
        }
        $overlapped = true;
        $worker->start();
        $deadline = microtime(true) + 8;
        while (! file_exists($directory.'/worker.started')) {
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('Timer worker did not start: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(10000);
        }
        usleep(300000);
        expect($worker->isRunning())->toBeTrue($worker->getOutput().$worker->getErrorOutput());
    });

    try {
        if ($write === 'create') {
            $created = app(TimeEntryService::class)->create($user, [
                'project_id' => $project->id, 'task_id' => $task->id,
                'spent_on' => now()->toDateString(), 'hours' => 1.0, 'notes' => null,
            ]);
            expect($created->exists)->toBeTrue();
        } else {
            $lock = app(MonthLockService::class)->lock(
                $project->client, CarbonImmutable::today()->startOfMonth()->subMonth(),
                CarbonImmutable::today(), $user,
            );
            expect($lock->exists)->toBeTrue();
        }
        $worker->wait();
        expect($overlapped)->toBeTrue()
            ->and($worker->getExitCode())->toBe(0, $worker->getOutput().$worker->getErrorOutput())
            ->and($worker->getOutput())->toBe('committed')
            ->and($entry->fresh()->is_running)->toBe($timerAction === 'timer-start');
    } finally {
        $worker->stop();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    'entry creation and start' => ['create', 'timer-start'],
    'entry creation and stop' => ['create', 'timer-stop'],
    'month closure and stop' => ['lock', 'timer-stop'],
]);
