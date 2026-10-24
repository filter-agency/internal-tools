<?php

namespace App\Livewire\Reports;

use App\Domain\Reporting\ContractPositionTimeline;
use App\Domain\Reporting\TotalsDto;
use App\Enums\GroupBy;
use App\Livewire\Reports\Concerns\HasReportPeriod;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class TeamReport extends Component
{
    use HasReportPeriod;

    public User $member;

    public string $groupBy = 'project';

    public function mount(User $user): void
    {
        $this->member = $user;
        $this->mountHasReportPeriod();
    }

    public function totals(): TotalsDto
    {
        return $this->buildQuery($this->member->id)->totals();
    }

    /** @return Collection<int, \stdClass> */
    public function rows(): Collection
    {
        return $this->buildQuery($this->member->id)->groupBy(GroupBy::tryFrom($this->groupBy) ?? GroupBy::Project);
    }

    #[Renderless]
    public function export(): StreamedResponse
    {
        return $this->exportCsv($this->member->id);
    }

    public function render(): View
    {
        $asAt = $this->contractPositionAsAt();

        return view('livewire.reports.team-report', [
            'totals' => $this->totals(),
            'rows' => $this->rows(),
            'position' => ContractPositionTimeline::load()->labelOn($this->member->id, $asAt),
            'positionAsAt' => $asAt,
        ]);
    }
}
