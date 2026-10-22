<?php

namespace App\Domain\Reporting;

use App\Enums\GroupBy;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class TimeReportQuery
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $userId = null,
        public ?int $clientId = null,
        public ?int $projectId = null,
        public ?int $taskId = null,
        public bool $billableOnly = false,
        public bool $activeProjectsOnly = false,
    ) {}

    public function totals(): TotalsDto
    {
        /** @var object{total_hours: string, billable_hours: string, billable_amount: string, uninvoiced_amount: string}|null $rows */
        $rows = $this->baseQuery()
            ->toBase()
            ->selectRaw('
                COALESCE(SUM(time_entries.hours), 0) as total_hours,
                COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.hours ELSE 0 END), 0) as billable_hours,
                COALESCE(SUM(time_entries.billable_amount), 0) as billable_amount,
                COALESCE(SUM(CASE WHEN time_entries.is_billable AND time_entries.invoiced_at IS NULL THEN time_entries.billable_amount ELSE 0 END), 0) as uninvoiced_amount
            ')
            ->first();

        if ($rows === null) {
            return TotalsDto::empty();
        }

        $totalHours = (float) $rows->total_hours;
        $billableHours = (float) $rows->billable_hours;
        $billableAmount = (float) $rows->billable_amount;
        $uninvoicedAmount = (float) $rows->uninvoiced_amount;
        $billablePercent = $totalHours > 0 ? round($billableHours / $totalHours * 100, 1) : 0.0;

        return new TotalsDto($totalHours, $billableHours, $billableAmount, $uninvoicedAmount, $billablePercent);
    }

    /**
     * Aggregate rows grouped by the given dimension.
     *
     * @return Collection<int, \stdClass>
     */
    public function groupBy(GroupBy $dim): Collection
    {
        return $this->groupedReport($dim)->rows;
    }

    public function groupedReport(GroupBy $dim): GroupedReportDto
    {
        $rows = $this->groupedQuery($dim)
            ->get()
            ->map(function (object $row): \stdClass {
                /** @var \stdClass */
                return (object) [
                    'id' => (int) $row->id,
                    'label' => (string) $row->label,
                    'client_name' => isset($row->client_name) ? (string) $row->client_name : null,
                    'colour' => isset($row->colour) ? (string) $row->colour : null,
                    'total_hours' => round((float) $row->total_hours, 2),
                    'billable_hours' => round((float) $row->billable_hours, 2),
                    'billable_amount' => round((float) $row->billable_amount, 2),
                    'uninvoiced_amount' => round((float) $row->uninvoiced_amount, 2),
                ];
            });

        $totalHours = round((float) $rows->sum('total_hours'), 2);
        $billableHours = round((float) $rows->sum('billable_hours'), 2);

        return new GroupedReportDto(
            $rows,
            new TotalsDto(
                $totalHours,
                $billableHours,
                round((float) $rows->sum('billable_amount'), 2),
                round((float) $rows->sum('uninvoiced_amount'), 2),
                $totalHours > 0 ? round($billableHours / $totalHours * 100, 1) : 0.0,
            ),
        );
    }

    private function groupedQuery(GroupBy $dim): \Illuminate\Database\Query\Builder
    {
        $query = $this->baseQuery()->toBase();

        switch ($dim) {
            case GroupBy::Client:
                $query->join('projects', 'time_entries.project_id', '=', 'projects.id')
                    ->join('clients', 'projects.client_id', '=', 'clients.id')
                    ->selectRaw('
                        clients.id as id,
                        clients.name as label,
                        COALESCE(SUM(time_entries.hours), 0) as total_hours,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.hours ELSE 0 END), 0) as billable_hours,
                        COALESCE(SUM(time_entries.billable_amount), 0) as billable_amount,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable AND time_entries.invoiced_at IS NULL THEN time_entries.billable_amount ELSE 0 END), 0) as uninvoiced_amount
                    ')
                    ->groupBy('clients.id', 'clients.name')
                    ->orderBy('clients.name');
                break;

            case GroupBy::Project:
                $query->join('projects', 'time_entries.project_id', '=', 'projects.id')
                    ->join('clients', 'projects.client_id', '=', 'clients.id')
                    ->selectRaw('
                        projects.id as id,
                        projects.name as label,
                        clients.name as client_name,
                        COALESCE(SUM(time_entries.hours), 0) as total_hours,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.hours ELSE 0 END), 0) as billable_hours,
                        COALESCE(SUM(time_entries.billable_amount), 0) as billable_amount,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable AND time_entries.invoiced_at IS NULL THEN time_entries.billable_amount ELSE 0 END), 0) as uninvoiced_amount
                    ')
                    ->groupBy('projects.id', 'projects.name', 'clients.name')
                    ->orderBy('clients.name')
                    ->orderBy('projects.name');
                break;

            case GroupBy::Task:
                $query->join('tasks', 'time_entries.task_id', '=', 'tasks.id')
                    ->selectRaw('
                        tasks.id as id,
                        tasks.name as label,
                        tasks.colour as colour,
                        COALESCE(SUM(time_entries.hours), 0) as total_hours,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.hours ELSE 0 END), 0) as billable_hours,
                        COALESCE(SUM(time_entries.billable_amount), 0) as billable_amount,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable AND time_entries.invoiced_at IS NULL THEN time_entries.billable_amount ELSE 0 END), 0) as uninvoiced_amount
                    ')
                    ->groupBy('tasks.id', 'tasks.name', 'tasks.colour')
                    ->orderByRaw('total_hours DESC');
                break;

            case GroupBy::User:
                $query->join('users', 'time_entries.user_id', '=', 'users.id')
                    ->selectRaw('
                        users.id as id,
                        users.name as label,
                        COALESCE(SUM(time_entries.hours), 0) as total_hours,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.hours ELSE 0 END), 0) as billable_hours,
                        COALESCE(SUM(time_entries.billable_amount), 0) as billable_amount,
                        COALESCE(SUM(CASE WHEN time_entries.is_billable AND time_entries.invoiced_at IS NULL THEN time_entries.billable_amount ELSE 0 END), 0) as uninvoiced_amount
                    ')
                    ->groupBy('users.id', 'users.name')
                    ->orderBy('users.name');
                break;
        }

        return $query;
    }

    /**
     * Lazy stream of raw TimeEntry models for CSV export.
     *
     * @return LazyCollection<int, TimeEntry>
     */
    public function entries(): LazyCollection
    {
        /** @var LazyCollection<int, TimeEntry> */
        // Offset pagination with a fully deterministic order (date, then id).
        // Do not switch this back to lazyById(): keyset pagination only strips
        // existing ORDER BY clauses for its own cursor column, so combined with
        // orderBy(spent_on) every page came back date-sorted while the "last
        // id" cursor pointed at an arbitrary row. Any export over 200 rows then
        // skipped some entries and repeated others.
        return $this->baseQuery()
            ->with(['project.client', 'task', 'user'])
            ->orderBy('time_entries.spent_on')
            ->orderBy('time_entries.id')
            ->lazy(200);
    }

    /**
     * Paginated, newest-first TimeEntry models for on-screen display, with
     * relations eager-loaded (project.client, task, user, asanaTask).
     *
     * @return LengthAwarePaginator<TimeEntry>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<TimeEntry> */
        return $this->baseQuery()
            ->with(['project.client', 'task', 'user', 'asanaTask'])
            ->orderByDesc('time_entries.spent_on')
            ->orderByDesc('time_entries.id')
            ->paginate($perPage);
    }

    /** @return Builder<TimeEntry> */
    private function baseQuery(): Builder
    {
        $query = TimeEntry::query()
            ->where('time_entries.spent_on', '>=', $this->from->toDateString())
            ->where('time_entries.spent_on', '<', $this->to->addDay()->toDateString());

        if ($this->userId !== null) {
            $query->where('time_entries.user_id', $this->userId);
        }

        if ($this->projectId !== null) {
            $query->where('time_entries.project_id', $this->projectId);
        }

        if ($this->taskId !== null) {
            $query->where('time_entries.task_id', $this->taskId);
        }

        if ($this->billableOnly) {
            $query->where('time_entries.is_billable', true);
        }

        if ($this->clientId !== null) {
            $clientId = $this->clientId;
            $query->whereHas('project', fn (Builder $q) => $q->where('client_id', $clientId));
        }

        if ($this->activeProjectsOnly) {
            $query->whereHas('project', fn (Builder $q) => $q->where('is_archived', false));
        }

        return $query;
    }
}
