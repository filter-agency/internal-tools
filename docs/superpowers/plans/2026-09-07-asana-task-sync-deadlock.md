# Asana Task Sync Deadlock Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent concurrent Asana task refreshes from deadlocking while keeping each cached board snapshot complete and current.

**Architecture:** Make `PullAsanaTasksJob` unique per Asana project so scheduled and on-demand dispatches cannot queue overlapping work for the same board. Inside the job, sort task writes by GID and persist the upserts plus stale-row prune in one Laravel transaction with three concurrency retries; the existing queue-level three attempts and 30-second backoff remain the final fallback if local retries are exhausted.

**Tech Stack:** PHP 8.2, Laravel 11.51, Eloquent, Laravel database queue/cache locks, Pest 3, SQLite automated tests, MySQL 8 production.

**Spec:** [Asana issue FLTR-2482 — Asana task sync deadlocks while updating asana_tasks](https://app.asana.com/1/155579732034488/project/1204439707387883/task/1218202151547393)

## Global Constraints

- Preserve the existing actor fallback, upstream-error logging, completion logging, and three job attempts with a 30-second backoff.
- Continue to retain cached tasks when Asana returns an empty task list; an empty response must not prune the board.
- Scope uniqueness by `asanaProjectGid`, so different boards may still refresh concurrently.
- Acquire the unique-job lock through Laravel's configured cache store; production must keep using a lock-capable shared store such as the existing database cache.
- Keep all database mutations outside the Asana HTTP request phase so no transaction remains open during network I/O.
- Do not add or alter database columns or indexes.

---

### Task 1: Add concurrency regression coverage

**Files:**
- Create: `tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php`
- Test: `tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php`

**Interfaces:**
- Consumes: `PullAsanaTasksJob::__construct(string $asanaProjectGid, int $userId, array $fallbackUserIds = [])` and `PullAsanaTasksJob::handle(AsanaService $service): void`.
- Produces: Migration-isolated regression coverage for per-board dispatch deduplication, deterministic deadlock retry, and atomic rollback when snapshot pruning fails.

- [ ] **Step 1: Create the migration-isolated concurrency test file**

Create the file with its own connected-user fixture:

```php
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
```

Use `DatabaseMigrations`, not `RefreshDatabase`, so the job's transaction is the outermost transaction. Laravel intentionally rethrows concurrency errors from nested transactions, while production executes this job at transaction level zero.

- [ ] **Step 2: Add the queue-uniqueness regression test**

```php
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
```

- [ ] **Step 3: Add a deadlock-retry and lock-order regression test**

Create two existing cached tasks, return them from the fake Asana API in reverse GID order, and inject one `SQLSTATE[40001]` failure immediately before the first task update:

```php
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
```

The repeated `t-a` proves Laravel restarted the transaction after the injected deadlock; `t-a` preceding `t-z` proves writes no longer depend on Asana response order.

- [ ] **Step 4: Add an atomic-snapshot rollback regression test**

```php
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
```

- [ ] **Step 5: Run the focused tests and confirm they fail for the intended reasons**

Run:

```bash
php artisan test tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php
```

Expected: the duplicate dispatch count is `3`, the injected deadlock escapes without retry, and the task update remains committed after the injected prune failure.

- [ ] **Step 6: Commit the failing regression tests**

```bash
git add tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php
git commit -m "test: cover Asana task sync concurrency"
```

### Task 2: Deduplicate and transactionally persist task pulls

**Files:**
- Modify: `app/Jobs/Asana/PullAsanaTasksJob.php:18`
- Test: `tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php`

**Interfaces:**
- Consumes: Laravel's `ShouldBeUnique` dispatch contract, the configured cache lock store, and `DB::transaction(Closure $callback, int $attempts)`.
- Produces: `PullAsanaTasksJob::uniqueId(): string` keyed by Asana project GID and `PullAsanaTasksJob::persistTasks(array $tasks): void` for deterministic, atomic snapshot writes.

- [ ] **Step 1: Make queued task pulls unique per Asana project**

Add the imports and contract:

```php
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

class PullAsanaTasksJob implements ShouldQueue, ShouldBeUnique
```

Add the board-scoped unique identifier:

```php
public function uniqueId(): string
{
    return $this->asanaProjectGid;
}
```

Do not set `uniqueFor`: Laravel must retain the lock until this queued job completes or exhausts its retries, rather than allowing a slow refresh to overlap after an arbitrary timeout.

- [ ] **Step 2: Move snapshot persistence behind one deterministic transaction**

Replace the inline write/prune block in `handle()` with:

```php
$tasks = $result['tasks'];

$this->persistTasks($tasks);
```

Add the typed persistence method:

```php
/**
 * @param  list<array{gid: string, name: string, search_text: string|null, completed: bool, parent_gid: string|null}>  $tasks
 */
private function persistTasks(array $tasks): void
{
    if ($tasks === []) {
        return;
    }

    usort($tasks, static fn (array $left, array $right): int => strcmp($left['gid'], $right['gid']));

    $now = now();
    $seenGids = array_column($tasks, 'gid');

    DB::transaction(function () use ($tasks, $now, $seenGids): void {
        foreach ($tasks as $task) {
            AsanaTask::updateOrCreate(
                ['gid' => $task['gid']],
                [
                    'asana_project_gid' => $this->asanaProjectGid,
                    'name' => $task['name'],
                    'search_text' => $task['search_text'],
                    'is_completed' => $task['completed'],
                    'parent_gid' => $task['parent_gid'],
                    'last_synced_at' => $now,
                ],
            );
        }

        AsanaTask::query()
            ->where('asana_project_gid', $this->asanaProjectGid)
            ->whereNotIn('gid', $seenGids)
            ->delete();
    }, 3);
}
```

This keeps the transaction limited to local database work. Laravel 11 recognizes MySQL `SQLSTATE[40001]` and retries the complete closure up to three times; sorting by GID gives concurrent snapshots a consistent row-lock order before the prune query.

- [ ] **Step 3: Run the focused concurrency tests**

Run:

```bash
php artisan test tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php
```

Expected: PASS; same-board dispatches collapse to one queued job, the first injected deadlock retries successfully, and a non-concurrency prune failure rolls back the task update.

- [ ] **Step 4: Run all Asana pull-job tests**

Run:

```bash
php artisan test tests/Feature/Asana/PullJobsTest.php tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php
```

Expected: PASS, including long task names, actor fallback, stale-cache preservation on Asana 5xx, and failure logging for non-server HTTP errors.

- [ ] **Step 5: Commit the implementation**

```bash
git add app/Jobs/Asana/PullAsanaTasksJob.php tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php
git commit -m "fix: prevent Asana task sync deadlocks"
```

### Task 3: Verify repository and production assumptions

**Files:**
- Verify: `app/Jobs/Asana/PullAsanaTasksJob.php`
- Verify: `tests/Feature/Asana/PullJobsTest.php`
- Verify: `tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php`
- Verify: `config/cache.php`
- Verify: `docs/asana-deployment.md`

**Interfaces:**
- Consumes: The repository's CI commands and the production cache/queue configuration.
- Produces: Release evidence that the change passes static and behavioral checks and that all workers share the unique-job lock store.

- [ ] **Step 1: Run PHP formatting and static analysis**

Run:

```bash
./vendor/bin/pint --test
php -d memory_limit=512M ./vendor/bin/phpstan analyse --no-progress
```

Expected: both commands exit `0` with no formatting or type errors.

- [ ] **Step 2: Run the full automated test suite**

Run:

```bash
php -d memory_limit=512M ./vendor/bin/pest --ci
npm run build
```

Expected: Pest exits `0`, and Vite completes a production build.

- [ ] **Step 3: Review the final diff**

Run:

```bash
git diff --check
git diff -- app/Jobs/Asana/PullAsanaTasksJob.php tests/Feature/Asana/PullAsanaTasksConcurrencyTest.php docs/superpowers/plans/2026-09-07-asana-task-sync-deadlock.md
```

Expected: no whitespace errors; the diff contains only the job concurrency guard, transactional persistence, regression tests, and this plan.

- [ ] **Step 4: Verify the deployment lock store before release**

On the production host, run:

```bash
php artisan about --only=cache,environment
```

Expected: the default cache store is a shared, lock-capable backend. The repository default is `database`; do not release with `array` or `null`, and do not use `file` if queue workers run on multiple hosts.

- [ ] **Step 5: Commit the plan if it is not already included**

```bash
git add docs/superpowers/plans/2026-09-07-asana-task-sync-deadlock.md
git commit -m "docs: plan Asana task sync deadlock fix"
```

- [ ] **Step 6: Verify the first scheduled production refresh**

After an explicitly approved deployment, wait for one 15-minute `asana:refresh-tasks` cycle and confirm that `asana.pull_tasks.completed` entries resume without a new `SQLSTATE[40001]` event. Trigger one on-demand refresh during the cycle and confirm it is deduplicated rather than creating an overlapping pull for the same board.
