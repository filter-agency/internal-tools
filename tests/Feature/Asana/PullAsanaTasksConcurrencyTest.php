<?php

use App\Jobs\Asana\PullAsanaTasksJob;
use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(DatabaseMigrations::class);

function asanaConcurrencyConnectedUser(): User
{
    return User::factory()->create([
        'asana_access_token' => 'tok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'me',
        'asana_workspace_gid' => 'WS1',
    ]);
}

test('duplicate task pulls are queued once per Asana project', function () {
    config([
        'queue.default' => 'database',
        'cache.default' => 'array',
    ]);

    $first = PullAsanaTasksJob::dispatch('P1', 1);
    unset($first);

    $duplicate = PullAsanaTasksJob::dispatch('P1', 2);
    unset($duplicate);

    $otherBoard = PullAsanaTasksJob::dispatch('P2', 1);
    unset($otherBoard);

    expect(DB::table('jobs')->count())->toBe(2);
});

test('pulling tasks retries a deadlock in deterministic gid order', function () {
    $user = asanaConcurrencyConnectedUser();

    foreach (['t-a', 't-z'] as $gid) {
        AsanaTask::create([
            'gid' => $gid,
            'asana_project_gid' => 'P1',
            'name' => 'Old '.$gid,
            'search_text' => null,
            'is_completed' => false,
            'parent_gid' => null,
            'last_synced_at' => now()->subHour(),
        ]);
    }

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response([
            'data' => [
                ['gid' => 't-z', 'name' => 'Fresh Z', 'completed' => false, 'parent' => null],
                ['gid' => 't-a', 'name' => 'Fresh A', 'completed' => false, 'parent' => null],
            ],
            'next_page' => null,
        ]),
    ]);

    $writeOrder = [];
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$writeOrder): void {
        if (! str_starts_with($query, 'update "asana_tasks"')) {
            return;
        }

        $writeOrder[] = (string) end($bindings);

        if (count($writeOrder) === 1) {
            throw new PDOException('SQLSTATE[40001]: Serialization failure: deadlock', 40001);
        }
    });

    (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class));

    expect($writeOrder)->toBe(['t-a', 't-a', 't-z'])
        ->and(AsanaTask::find('t-a')?->name)->toBe('Fresh A')
        ->and(AsanaTask::find('t-z')?->name)->toBe('Fresh Z')
        ->and(AsanaSyncLog::where('event', 'asana.pull_tasks.completed')->count())->toBe(1);
});

test('pulling tasks rolls back updates when snapshot pruning fails', function () {
    $user = asanaConcurrencyConnectedUser();

    AsanaTask::create([
        'gid' => 't-current',
        'asana_project_gid' => 'P1',
        'name' => 'Old current name',
        'search_text' => null,
        'is_completed' => false,
        'parent_gid' => null,
        'last_synced_at' => now()->subHour(),
    ]);
    AsanaTask::create([
        'gid' => 't-stale',
        'asana_project_gid' => 'P1',
        'name' => 'Stale task',
        'search_text' => null,
        'is_completed' => false,
        'parent_gid' => null,
        'last_synced_at' => now()->subHour(),
    ]);

    Http::fake([
        'app.asana.com/api/1.0/projects/P1/tasks*' => Http::response([
            'data' => [
                ['gid' => 't-current', 'name' => 'Fresh current name', 'completed' => false, 'parent' => null],
            ],
            'next_page' => null,
        ]),
    ]);

    $failPrune = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$failPrune): void {
        if ($failPrune && str_starts_with($query, 'delete from "asana_tasks"')) {
            $failPrune = false;

            throw new RuntimeException('Injected prune failure');
        }
    });

    expect(fn () => (new PullAsanaTasksJob('P1', $user->id))->handle(app(AsanaService::class)))
        ->toThrow(RuntimeException::class, 'Injected prune failure');

    expect(AsanaTask::find('t-current')?->name)->toBe('Old current name')
        ->and(AsanaTask::find('t-stale'))->not->toBeNull()
        ->and(AsanaSyncLog::where('event', 'asana.pull_tasks.completed')->exists())->toBeFalse();
});
