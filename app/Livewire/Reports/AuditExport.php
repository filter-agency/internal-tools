<?php

namespace App\Livewire\Reports;

use App\Domain\Reporting\AuditTimeCsvExport;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class AuditExport extends Component
{
    public string $from = '';

    public string $to = '';

    /** @var array<int, int|string> */
    public array $clientIds = [];

    public function mount(): void
    {
        $lastMonth = CarbonImmutable::today()->startOfMonth()->subMonth();

        $this->from = $lastMonth->toDateString();
        $this->to = $lastMonth->endOfMonth()->toDateString();
        // Archived JDW clients stay in the default: their hours are part of any historical range.
        $this->clientIds = Client::query()->jdw()->orderBy('name')->pluck('id')->all();
    }

    /**
     * Errors show as the form is edited, one field at a time: download() is
     * renderless, so a failure raised there would never reach the page.
     */
    public function updated(string $property): void
    {
        $field = (string) str($property)->before('.');

        $this->validateOnly($field);

        // The range rule spans both dates, so moving one can invalidate the
        // other. Check it too, unless it is still empty (that is not this edit's doing).
        $other = ['from' => 'to', 'to' => 'from'][$field] ?? null;

        if ($other !== null && $this->{$other} !== '') {
            $this->validateOnly($other);
        }
    }

    #[Renderless]
    public function download(): StreamedResponse
    {
        $this->validate();

        $export = new AuditTimeCsvExport(
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->to),
            array_map('intval', $this->clientIds),
        );

        return response()->streamDownload(function () use ($export): void {
            $handle = fopen('php://output', 'w');
            assert($handle !== false);
            $export->writeTo($handle);
        }, 'audit-time-'.$this->from.'-to-'.$this->to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        return view('livewire.reports.audit-export', [
            'clients' => Client::query()->orderBy('name')->get(),
        ]);
    }

    /** @return array<string, array<int, string>> */
    protected function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'clientIds' => ['required', 'array', 'min:1'],
            'clientIds.*' => ['integer', 'exists:clients,id'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'from.required' => 'Enter a start date.',
            'from.date_format' => 'Enter a valid start date.',
            'to.required' => 'Enter an end date.',
            'to.date_format' => 'Enter a valid end date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'clientIds.required' => 'Pick at least one client.',
            'clientIds.min' => 'Pick at least one client.',
        ];
    }
}
