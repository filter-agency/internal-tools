<?php

namespace App\Livewire\Reports\Concerns;

use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Models\Client;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HasReportPeriod
{
    #[Url(except: 'this_month')]
    public string $preset = 'this_month';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public bool $showArchived = true;

    public function mountHasReportPeriod(): void
    {
        $this->applyPreset($this->preset);
    }

    public function updatedPreset(string $value): void
    {
        if ($value !== 'custom') {
            $this->applyPreset($value);
        }
    }

    private function applyPreset(string $preset): void
    {
        $now = CarbonImmutable::now();

        match ($preset) {
            'this_week' => [$this->from, $this->to] = [$now->startOfWeek()->toDateString(), $now->endOfWeek()->toDateString()],
            'this_month' => [$this->from, $this->to] = [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()],
            'last_month' => [$this->from, $this->to] = [$now->subMonth()->startOfMonth()->toDateString(), $now->subMonth()->endOfMonth()->toDateString()],
            'last_3' => [$this->from, $this->to] = [$now->subMonths(3)->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()],
            'this_year' => [$this->from, $this->to] = [$now->startOfYear()->toDateString(), $now->endOfYear()->toDateString()],
            'last_year' => [$this->from, $this->to] = [$now->subYear()->startOfYear()->toDateString(), $now->subYear()->endOfYear()->toDateString()],
            default => null,
        };
    }

    public function exportCsv(?int $userId = null, ?int $clientId = null, ?int $projectId = null): StreamedResponse
    {
        $query = $this->buildQuery($userId, $clientId, $projectId);
        $export = new DetailedTimeCsvExport($query);
        $filename = $this->buildExportFilename($clientId, $projectId);

        return response()->streamDownload(function () use ($export): void {
            $handle = fopen('php://output', 'w');
            assert($handle !== false);
            $export->writeTo($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The date contract positions are read at: the period end, or today when the period ends in the future. */
    protected function contractPositionAsAt(): CarbonImmutable
    {
        return min(CarbonImmutable::parse($this->to), CarbonImmutable::today());
    }

    protected function buildQuery(?int $userId = null, ?int $clientId = null, ?int $projectId = null): TimeReportQuery
    {
        return new TimeReportQuery(
            from: CarbonImmutable::parse($this->from),
            to: CarbonImmutable::parse($this->to),
            userId: $userId,
            clientId: $clientId,
            projectId: $projectId,
            activeProjectsOnly: ! $this->showArchived,
        );
    }

    private function buildExportFilename(?int $clientId, ?int $projectId): string
    {
        $scope = '';
        if ($projectId !== null) {
            $project = Project::find($projectId);
            if ($project !== null) {
                $scope = '-'.Str::slug($project->code !== '' ? $project->code : $project->name);
            }
        } elseif ($clientId !== null) {
            $client = Client::find($clientId);
            if ($client !== null) {
                $scope = '-'.Str::slug($client->name);
            }
        }

        $archivedNote = $this->showArchived ? '' : '-excluding-archived';

        return 'detailed-time'.$scope.'-'.$this->from.'-to-'.$this->to.$archivedNote.'.csv';
    }
}
