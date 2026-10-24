<?php

namespace App\Domain\Reporting;

use App\Domain\TimeTracking\HoursFormatter;

final class DetailedTimeCsvExport
{
    public const HEADERS = [
        'Date', 'Client', 'Project', 'Project Code', 'Task', 'Notes', 'Hours',
        'Billable?', 'Invoiced?', 'Approved?', 'First Name', 'Last Name',
        'Employee Id', 'Roles', 'Employee?', 'Billable Rate', 'Billable Amount',
        'Cost Rate', 'Cost Amount', 'Currency', 'External Reference URL',
        'Contract Position', 'Time Entry Id',
    ];

    public function __construct(private readonly TimeReportQuery $query) {}

    /**
     * Write the full CSV (headers + rows) to the given output stream.
     * Uses \r\n line endings per RFC 4180. No BOM.
     *
     * Free-text cells go through CsvFormatter::text() so a spreadsheet never
     * evaluates them as formulas; harvest:import strips that prefix again.
     *
     * @param  resource  $handle  A writable stream (e.g. php://output or fopen())
     */
    public function writeTo(mixed $handle): void
    {
        fwrite($handle, implode(',', self::HEADERS)."\r\n");

        $positions = ContractPositionTimeline::load();

        foreach ($this->query->entries() as $entry) {
            $project = $entry->project;
            $client = $project->client;
            $task = $entry->task;
            $user = $entry->user;

            $nameParts = explode(' ', $user->name, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            $isBillable = (bool) $entry->is_billable;
            $rate = $isBillable ? (float) $entry->billable_rate_snapshot : 0.0;
            $amount = $isBillable ? (float) $entry->billable_amount : 0.0;

            $row = [
                CsvFormatter::field($entry->spent_on->format('Y-m-d')),
                CsvFormatter::text($client->name),
                CsvFormatter::text($project->name),
                CsvFormatter::text($project->code ?? ''),
                CsvFormatter::text($task->name),
                CsvFormatter::text($entry->notes ?? ''),
                HoursFormatter::asReportDecimal((float) $entry->hours),
                $isBillable ? 'Yes' : 'No',
                'No',
                'No',
                CsvFormatter::text($firstName),
                CsvFormatter::text($lastName),
                '',
                CsvFormatter::text($user->role_title ?? ''),
                ! $user->is_contractor ? 'Yes' : 'No',
                CsvFormatter::hours($rate),
                CsvFormatter::hours($amount),
                '0.0',
                '0.0',
                'British Pound - GBP',
                CsvFormatter::text($entry->external_reference ?? ''),
                CsvFormatter::text($positions->labelOn($entry->user_id, $entry->spent_on)),
                (string) $entry->id,
            ];

            fwrite($handle, implode(',', $row)."\r\n");
        }
    }

    /**
     * Return the full CSV as a string (for testing; avoid for large exports).
     */
    public function toCsv(): string
    {
        $handle = fopen('php://memory', 'r+');
        assert($handle !== false);
        $this->writeTo($handle);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }
}
