<?php

namespace App\Livewire\Reports;

use App\Domain\Reporting\MonthReconciliation;
use App\Domain\Reporting\ReconciliationRow;
use App\Models\MonthLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class ReconciliationReport extends Component
{
    /** Year and month in Y-m form. Anything that is not a locked month shows the newest one. */
    #[Url(except: '')]
    public string $month = '';

    public function mount(): void
    {
        $this->month = $this->selectedMonth($this->lockedMonths());
    }

    public function render(): View
    {
        $months = $this->lockedMonths();
        $selected = $this->selectedMonth($months);

        if ($selected === '') {
            return view('livewire.reports.reconciliation-report', [
                'months' => [],
                'clients' => collect(),
                'changes' => collect(),
            ]);
        }

        $month = CarbonImmutable::parse($selected.'-01');

        return view('livewire.reports.reconciliation-report', [
            'months' => $months,
            'clients' => $this->clientGroups(MonthReconciliation::rows($month)),
            'changes' => MonthReconciliation::changes($month),
        ]);
    }

    /**
     * Months with at least one lock, newest first.
     *
     * @return array<string, string> month label keyed by Y-m
     */
    private function lockedMonths(): array
    {
        return MonthLock::query()
            ->select('month')
            ->distinct()
            ->orderByDesc('month')
            ->get()
            ->mapWithKeys(fn (MonthLock $lock): array => [$lock->month->format('Y-m') => $lock->label()])
            ->all();
    }

    /** @param  array<string, string>  $months */
    private function selectedMonth(array $months): string
    {
        return array_key_exists($this->month, $months) ? $this->month : (string) array_key_first($months);
    }

    /**
     * Rows grouped by client, then project, each group carrying its subtotal.
     *
     * @param  Collection<int, ReconciliationRow>  $rows
     * @return Collection<int, array{id: int, name: string, issued: float, current: float, difference: float, projects: Collection<int, array{id: int, name: string, issued: float, current: float, difference: float, rows: Collection<int, ReconciliationRow>}>}>
     */
    private function clientGroups(Collection $rows): Collection
    {
        return $rows->groupBy('clientId')->map(function (Collection $clientRows): array {
            $client = $clientRows->firstOrFail();

            return [
                'id' => $client->clientId,
                'name' => $client->clientName,
                ...$this->subtotal($clientRows),
                'projects' => $clientRows->groupBy('projectId')->map(function (Collection $projectRows): array {
                    $project = $projectRows->firstOrFail();

                    return [
                        'id' => $project->projectId,
                        'name' => $project->projectName,
                        ...$this->subtotal($projectRows),
                        'rows' => $projectRows,
                    ];
                })->values(),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, ReconciliationRow>  $rows
     * @return array{issued: float, current: float, difference: float}
     */
    private function subtotal(Collection $rows): array
    {
        $issued = round($rows->sum('issuedHours'), 2);
        $current = round($rows->sum('currentHours'), 2);

        return ['issued' => $issued, 'current' => $current, 'difference' => round($current - $issued, 2)];
    }
}
