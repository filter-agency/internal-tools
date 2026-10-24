<?php

use App\Domain\Reporting\CsvFormatter;
use App\Domain\Reporting\DetailedTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Enums\ContractPosition;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserContractPosition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeExportEntry(array $attrs = []): TimeEntry
{
    $user = User::factory()->create(array_merge([
        'name' => 'Alice Smith',
        'role_title' => 'Senior Developer',
        'is_contractor' => false,
    ], $attrs['user'] ?? []));

    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Website Redesign',
        'code' => 'ACM001',
    ]);
    $task = Task::factory()->create(['name' => 'Development']);

    return TimeEntry::create(array_merge([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => '2026-04-01',
        'hours' => 2.0,
        'notes' => null,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => 168.0,
        'external_reference' => '10001',
    ], $attrs['entry'] ?? []));
}

function exportCsv(?int $userId = null): string
{
    $query = new TimeReportQuery(
        from: CarbonImmutable::parse('2026-04-01'),
        to: CarbonImmutable::parse('2026-04-30'),
        userId: $userId,
    );

    return (new DetailedTimeCsvExport($query))->toCsv();
}

it('freezes CSV export headers', function () {
    $csv = exportCsv();
    $headerLine = explode("\r\n", $csv)[0];

    expect($headerLine)->toBe(trim(file_get_contents(
        base_path('tests/Fixtures/csv-export-snapshot/headers.txt')
    )));
});

it('quotes fields containing double-quotes and escapes them', function () {
    expect(CsvFormatter::field('say "hello"'))->toBe('"say ""hello"""');
});

it('writes the correct 23 columns for a billable entry', function () {
    $entry = makeExportEntry();
    UserContractPosition::create([
        'user_id' => $entry->user_id,
        'position' => ContractPosition::SeniorDeveloper,
        'effective_from' => '2026-01-01',
    ]);
    $csv = exportCsv();
    $rows = explode("\r\n", trim($csv));
    $dataRow = $rows[1];
    $cols = str_getcsv($dataRow);

    expect($cols)->toHaveCount(23);
    expect($cols[0])->toBe('2026-04-01');          // Date
    expect($cols[1])->toBe('Acme Corp');            // Client
    expect($cols[2])->toBe('Website Redesign');     // Project
    expect($cols[3])->toBe('ACM001');               // Project Code
    expect($cols[4])->toBe('Development');          // Task
    expect($cols[6])->toBe('2.00');                 // Hours
    expect($cols[7])->toBe('Yes');                  // Billable?
    expect($cols[8])->toBe('No');                   // Invoiced?
    expect($cols[9])->toBe('No');                   // Approved?
    expect($cols[10])->toBe('Alice');               // First Name
    expect($cols[11])->toBe('Smith');               // Last Name
    expect($cols[12])->toBe('');                    // Employee Id
    expect($cols[13])->toBe('Senior Developer');    // Roles
    expect($cols[14])->toBe('Yes');                 // Employee?
    expect($cols[15])->toBe('84.0');                // Billable Rate
    expect($cols[16])->toBe('168.0');               // Billable Amount
    expect($cols[17])->toBe('0.0');                 // Cost Rate
    expect($cols[18])->toBe('0.0');                 // Cost Amount
    expect($cols[19])->toBe('British Pound - GBP'); // Currency
    expect($cols[20])->toBe('10001');               // External Reference
    expect($cols[21])->toBe('Senior Developer');    // Contract Position on the entry date
    expect($cols[22])->toBe((string) $entry->id);   // Time Entry Id
});

it('labels each row with the contract position held on its own date', function () {
    $entry = makeExportEntry(['entry' => ['spent_on' => '2026-04-10']]);
    $later = TimeEntry::create([
        'user_id' => $entry->user_id,
        'project_id' => $entry->project_id,
        'task_id' => $entry->task_id,
        'spent_on' => '2026-04-20',
        'hours' => 1.0,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => 84.0,
    ]);
    UserContractPosition::create(['user_id' => $entry->user_id, 'position' => ContractPosition::Developer, 'effective_from' => '2026-01-01']);
    UserContractPosition::create(['user_id' => $entry->user_id, 'position' => ContractPosition::SeniorDeveloper, 'effective_from' => '2026-04-15']);

    $rows = array_map('str_getcsv', array_slice(explode("\r\n", trim(exportCsv())), 1));

    expect($rows)->toHaveCount(2);
    expect($rows[0][0])->toBe('2026-04-10')->and($rows[0][21])->toBe('Developer')->and($rows[0][22])->toBe((string) $entry->id);
    expect($rows[1][0])->toBe('2026-04-20')->and($rows[1][21])->toBe('Senior Developer')->and($rows[1][22])->toBe((string) $later->id);
});

it('labels people with no contract position as outside the contracted team', function () {
    makeExportEntry();
    $cols = str_getcsv(explode("\r\n", trim(exportCsv()))[1]);

    expect($cols[21])->toBe('Outside contracted team');
});

it('outputs No for Billable? and zero rate/amount for non-billable entries', function () {
    makeExportEntry(['entry' => ['is_billable' => false, 'billable_rate_snapshot' => null, 'billable_amount' => 0.0]]);
    $csv = exportCsv();
    $cols = str_getcsv(explode("\r\n", trim($csv))[1]);

    expect($cols[7])->toBe('No');
    expect($cols[15])->toBe('0.0');
    expect($cols[16])->toBe('0.0');
});

it('streams every row when the export spans more than one lazyById chunk', function () {
    // lazyById chunks in blocks of 200. Exports that fit in a single chunk
    // never page a second time, so the bug only surfaces past that boundary.
    $user = User::factory()->create(['name' => 'Bulk User']);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->create();

    $count = 250;
    for ($i = 0; $i < $count; $i++) {
        TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => '2026-04-01',
            'hours' => 1.0,
            'is_running' => false,
            'is_billable' => true,
            'billable_rate_snapshot' => 84.0,
            'billable_amount' => 84.0,
        ]);
    }

    $csv = exportCsv($user->id);
    $dataRows = array_filter(explode("\r\n", trim($csv)));

    // header + every entry, with no RuntimeException from the second page.
    expect($dataRows)->toHaveCount($count + 1);
});

it('exports every entry exactly once when the result spans several pages', function () {
    $user = User::factory()->create(['name' => 'Alice Smith', 'role_title' => 'Developer', 'is_contractor' => false]);
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Website Redesign', 'code' => 'ACM001']);
    $task = Task::factory()->create(['name' => 'Development']);

    // 450 rows (more than two 200-row pages) inserted so that date order is
    // the reverse of id order: the shape that made the old id cursor skip
    // some entries and repeat others.
    foreach (range(1, 450) as $i) {
        TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'spent_on' => CarbonImmutable::parse('2026-04-30')->subDays($i % 30)->toDateString(),
            'hours' => 1.0,
            'notes' => null,
            'is_running' => false,
            'is_billable' => true,
            'billable_rate_snapshot' => 84.0,
            'billable_amount' => 84.0,
            'external_reference' => (string) (20000 + $i),
        ]);
    }

    $rows = explode("\r\n", trim(exportCsv()));
    array_shift($rows);
    $refs = array_map(fn (string $row): string => str_getcsv($row)[20], $rows);
    $dates = array_map(fn (string $row): string => str_getcsv($row)[0], $rows);
    $sorted = $dates;
    sort($sorted);

    expect($rows)->toHaveCount(450);
    expect(array_unique($refs))->toHaveCount(450);
    expect($dates)->toBe($sorted);
});
