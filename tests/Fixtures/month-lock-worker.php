<?php

use App\Domain\TimeTracking\MonthLockService;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

// Separate connections are essential: SQLite and nested transactions cannot
// exercise MySQL's blocking row locks.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$databaseConfig = json_decode(getenv('AUDIT_DATABASE_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
$databaseConfig['name'] = 'audit_concurrency';
config([
    'database.default' => 'audit_concurrency',
    'database.connections.audit_concurrency' => $databaseConfig,
    'queue.default' => 'sync',
    'cache.default' => 'array',
]);
Queue::fake();

[$script, $action, $userId, $projectId, $taskId, $adminId, $ready, $release, $hold] = $argv;
$entryId = $argv[9] ?? null;
$project = Project::findOrFail($projectId);
auth()->setUser(User::findOrFail($userId));
$entry = $entryId === null ? null : TimeEntry::findOrFail($entryId);
$month = CarbonImmutable::today()->startOfMonth()->subMonth();
file_put_contents($ready.'.started', 'started');

try {
    DB::transaction(function () use ($action, $userId, $project, $taskId, $adminId, $month, $ready, $release, $hold, $entry): void {
        if ($action === 'write') {
            app(TimeEntryService::class)->create(User::findOrFail($userId), [
                'project_id' => $project->id, 'task_id' => (int) $taskId,
                'spent_on' => $month->toDateString(), 'hours' => 1.0, 'notes' => null,
            ]);
        } elseif ($action === 'lock') {
            app(MonthLockService::class)->lock(
                $project->client, $month, CarbonImmutable::today(), User::findOrFail($adminId),
            );
        } else {
            $service = app(TimeEntryService::class);
            match ($action) {
                'timer-start' => $service->startTimer($entry),
                'timer-stop' => $service->stopTimer($entry),
                default => throw new InvalidArgumentException('Unknown concurrency action: '.$action),
            };
        }
        file_put_contents($ready, 'ready');
        if ($hold === '1') {
            $deadline = microtime(true) + 10;
            while (! file_exists($release)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Timed out waiting for the concurrency test to release the transaction.');
                }
                usleep(10000);
            }
        }
    });
    echo 'committed';
} catch (ValidationException $exception) {
    echo 'refused';
}
