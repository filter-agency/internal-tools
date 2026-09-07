<?php

namespace App\Jobs\Asana;

use App\Models\AsanaSyncLog;
use App\Models\AsanaTask;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class PullAsanaTasksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $asanaProjectGid,
        public readonly int $userId,
        /** @var array<int, int> */
        public readonly array $fallbackUserIds = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->asanaProjectGid;
    }

    public function handle(AsanaService $service): void
    {
        $result = $this->pullTasksWithAvailableActor($service);
        if ($result === null) {
            return;
        }

        /** @var User $user */
        $user = $result['user'];
        $tasks = $result['tasks'];

        $this->persistTasks($tasks);

        AsanaSyncLog::info('asana.pull_tasks.completed', [
            'asana_project_gid' => $this->asanaProjectGid,
            'user_id' => $user->id,
            'count' => count($tasks),
        ]);
    }

    /**
     * @param  list<array{gid: string, name: string, search_text: string|null, completed: bool, parent_gid: string|null}>  $tasks
     */
    private function persistTasks(array $tasks): void
    {
        // An empty response must not wipe the cache.
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

    /**
     * @return array{user: User, tasks: list<array{gid: string, name: string, search_text: string|null, completed: bool, parent_gid: string|null}>}|null
     */
    private function pullTasksWithAvailableActor(AsanaService $service): ?array
    {
        $permissionDeniedUserIds = [];
        $lastPermissionError = null;

        foreach ($this->candidateUserIds() as $userId) {
            $user = User::find($userId);
            if ($user === null || ! $user->asanaConnected()) {
                continue;
            }

            try {
                return [
                    'user' => $user,
                    'tasks' => $service->forUser($user)->getTasks($this->asanaProjectGid),
                ];
            } catch (RequestException $e) {
                if ($e->response->status() === 403) {
                    $permissionDeniedUserIds[] = $user->id;
                    $lastPermissionError = $e->getMessage();

                    continue;
                }

                if ($e->response->serverError()) {
                    AsanaSyncLog::warn('asana.pull_tasks.upstream_error', [
                        'asana_project_gid' => $this->asanaProjectGid,
                        'user_id' => $user->id,
                        'status' => $e->response->status(),
                        'error' => $e->getMessage(),
                    ], $user);

                    return null;
                }

                $this->logFailure($user, $e);

                throw $e;
            } catch (Throwable $e) {
                $this->logFailure($user, $e);

                throw $e;
            }
        }

        if ($permissionDeniedUserIds !== []) {
            AsanaSyncLog::warn('asana.pull_tasks.permission_denied', [
                'asana_project_gid' => $this->asanaProjectGid,
                'user_ids' => $permissionDeniedUserIds,
                'error' => $lastPermissionError,
            ]);
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function candidateUserIds(): array
    {
        return Collection::make([$this->userId, ...$this->fallbackUserIds])
            ->filter(fn ($userId): bool => is_int($userId) || ctype_digit((string) $userId))
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values()
            ->all();
    }

    private function logFailure(User $user, Throwable $e): void
    {
        AsanaSyncLog::error('asana.pull_tasks.failed', [
            'asana_project_gid' => $this->asanaProjectGid,
            'user_id' => $user->id,
            'error' => $e->getMessage(),
        ], $user);
    }
}
