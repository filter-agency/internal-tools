<?php

namespace App\Domain\TimeTracking;

use App\Domain\Reporting\ClientMonthTotals;
use App\Models\Client;
use App\Models\MonthLock;
use App\Models\MonthLockOverride;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MonthLockService
{
    public const OVERRIDE_MINUTES = 60;

    public const MIN_REASON_LENGTH = 10;

    /**
     * Lock a client's calendar month and snapshot the hours issued for it.
     * $month may be any date in the month.
     */
    public function lock(Client $client, CarbonImmutable $month, CarbonImmutable $issuedOn, User $admin): MonthLock
    {
        $this->assertAdmin($admin, 'Only admins can lock a month.');

        $start = $month->startOfMonth();
        $label = $start->format('F Y');
        $today = CarbonImmutable::today();

        if (! $start->endOfMonth()->startOfDay()->lt($today)) {
            throw ValidationException::withMessages([
                'month' => "{$label} has not ended yet, so it cannot be locked.",
            ]);
        }

        if ($issuedOn->startOfDay()->gt($today)) {
            throw ValidationException::withMessages([
                'issued_on' => 'The issued date cannot be in the future.',
            ]);
        }

        return DB::transaction(function () use ($client, $start, $label, $issuedOn, $admin): MonthLock {
            $alreadyLocked = MonthLock::query()
                ->where('client_id', $client->id)
                ->whereDate('month', $start->toDateString())
                ->exists();

            if ($alreadyLocked) {
                throw ValidationException::withMessages([
                    'month' => "{$label} is already locked for {$client->name}.",
                ]);
            }

            $running = TimeEntry::query()
                ->where('is_running', true)
                ->whereHas('project', fn ($query) => $query->where('client_id', $client->id))
                ->where('spent_on', '>=', $start->toDateString())
                ->where('spent_on', '<', $start->addMonth()->toDateString())
                ->with('user')
                ->orderBy('id')
                ->first();

            if ($running !== null) {
                throw ValidationException::withMessages([
                    'month' => "Stop {$running->user->name}'s running timer on {$client->name} before locking {$label}.",
                ]);
            }

            $lock = MonthLock::create([
                'client_id' => $client->id,
                'month' => $start->toDateString(),
                'issued_on' => $issuedOn->toDateString(),
                'locked_by_user_id' => $admin->id,
            ]);

            foreach (ClientMonthTotals::byProjectTask($client->id, $start) as $key => $hours) {
                [$projectId, $taskId] = array_map('intval', explode('|', $key));

                $lock->snapshots()->create([
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'hours' => $hours,
                ]);
            }

            return $lock;
        });
    }

    /**
     * Open an override that lets this admin edit the locked month for 60 minutes.
     * Any other active override this admin has on the lock is closed.
     */
    public function openOverride(MonthLock $lock, User $admin, string $reason): MonthLockOverride
    {
        $this->assertAdmin($admin, 'Only admins can override a locked month.');

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a reason of at least '.self::MIN_REASON_LENGTH.' characters.',
            ]);
        }

        return DB::transaction(function () use ($lock, $admin, $reason): MonthLockOverride {
            $lock->overrides()
                ->active()
                ->where('user_id', $admin->id)
                ->update(['closed_at' => now()]);

            return $lock->overrides()->create([
                'user_id' => $admin->id,
                'reason' => $reason,
                'expires_at' => now()->addMinutes(self::OVERRIDE_MINUTES),
            ]);
        });
    }

    public function closeOverride(MonthLockOverride $override): void
    {
        if ($override->closed_at === null) {
            $override->update(['closed_at' => now()]);
        }
    }

    private function assertAdmin(User $user, string $message): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException($message);
        }
    }
}
