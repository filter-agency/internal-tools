<?php

namespace App\Services;

use App\Domain\Schedule\ScheduleAvailabilityService;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class TimesheetCompletionService
{
    public function __construct(private readonly ScheduleAvailabilityService $scheduleAvailability) {}

    public function weeklyTarget(User $user): float
    {
        return $user->effectiveWeeklyTarget();
    }

    public function weeklyHoursLogged(User $user, CarbonInterface $weekStart): float
    {
        $start = CarbonImmutable::instance($weekStart)->startOfDay();
        $end = $start->addDays(6);

        return (float) TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('spent_on', [$start->startOfDay(), $end->endOfDay()])
            ->sum('hours');
    }

    public function monthlyTarget(User $user, CarbonInterface $monthStart): float
    {
        $start = CarbonImmutable::instance($monthStart)->startOfMonth();

        return $this->scheduleAvailability->capacityForPeriod($user, $start, $start->endOfMonth());
    }

    public function monthlyHoursLogged(User $user, CarbonInterface $monthStart): float
    {
        $start = CarbonImmutable::instance($monthStart)->startOfMonth();
        $end = $start->endOfMonth();

        return (float) TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('spent_on', [$start->startOfDay(), $end->endOfDay()])
            ->sum('hours');
    }

    /**
     * Active users whose Mon–Wed total is below their scheduled-hours checkpoint.
     *
     * Run on Thursdays after the Monday-to-Wednesday checkpoint period has concluded.
     *
     * @return Collection<int, array{user: User, hours: float, target: float, threshold: float}>
     */
    public function usersBelowMidWeekThreshold(CarbonInterface $weekStart): Collection
    {
        $start = CarbonImmutable::instance($weekStart)->startOfDay();
        $endOfWednesday = $start->addDays(2);

        $users = User::query()->notificationsActive()->orderBy('name')->get();

        $totals = $this->aggregateHours($users->pluck('id')->all(), $start, $endOfWednesday);

        return $users->map(function (User $user) use ($totals, $start, $endOfWednesday) {
            $target = $user->effectiveWeeklyTarget();
            $threshold = $this->scheduleAvailability->capacityForPeriod($user, $start, $endOfWednesday);
            $hours = (float) ($totals[$user->id] ?? 0.0);

            return compact('user', 'hours', 'target', 'threshold');
        })
            ->filter(fn (array $row) => $row['hours'] < $row['threshold'])
            ->values();
    }

    /**
     * Active users whose total for the given week was below their weekly target.
     *
     * @return Collection<int, array{user: User, hours: float, target: float}>
     */
    public function usersWithIncompleteWeek(CarbonInterface $weekStart): Collection
    {
        $start = CarbonImmutable::instance($weekStart)->startOfDay();
        $end = $start->addDays(6);

        $users = User::query()->notificationsActive()->orderBy('name')->get();

        $totals = $this->aggregateHours($users->pluck('id')->all(), $start, $end);

        return $users->map(function (User $user) use ($totals) {
            $target = $user->effectiveWeeklyTarget();
            $hours = (float) ($totals[$user->id] ?? 0.0);

            return compact('user', 'hours', 'target');
        })
            ->filter(fn (array $row) => $row['hours'] < $row['target'])
            ->values();
    }

    /**
     * Active users whose total for the given month was below their pro-rata monthly target.
     *
     * @return Collection<int, array{user: User, hours: float, target: float}>
     */
    public function usersWithIncompleteMonth(CarbonInterface $monthStart): Collection
    {
        $start = CarbonImmutable::instance($monthStart)->startOfMonth();
        $end = $start->endOfMonth();

        $users = User::query()->notificationsActive()->orderBy('name')->get();

        $totals = $this->aggregateHours($users->pluck('id')->all(), $start, $end);

        return $users->map(function (User $user) use ($totals, $start) {
            $target = $this->monthlyTarget($user, $start);
            $hours = (float) ($totals[$user->id] ?? 0.0);

            return compact('user', 'hours', 'target');
        })
            ->filter(fn (array $row) => $row['hours'] < $row['target'])
            ->values();
    }

    /**
     * @param  array<int>  $userIds
     * @return array<int, float> user_id => hours
     */
    private function aggregateHours(array $userIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($userIds === []) {
            return [];
        }

        return TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('spent_on', [$start->startOfDay(), $end->endOfDay()])
            ->selectRaw('user_id, SUM(hours) as hours')
            ->groupBy('user_id')
            ->pluck('hours', 'user_id')
            ->map(fn ($hours) => (float) $hours)
            ->all();
    }
}
