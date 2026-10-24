<?php

use App\Domain\Reporting\AuditTimeCsvExport;
use App\Domain\Reporting\TimeReportQuery;
use App\Enums\ClientTaskBillabilityProfile;
use App\Enums\ContractPosition;
use App\Enums\GroupBy;
use App\Livewire\Reports\AuditExport;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserContractPosition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const AUDIT_HEADER = 'Name,Filter role,Contract position,Month,Client,Project,Task,Hours';

function auditUser(string $name = 'Alice Smith', ?string $roleTitle = 'Developer'): User
{
    return User::factory()->create(['name' => $name, 'role_title' => $roleTitle]);
}

function auditProject(string $clientName = 'JDW Management', string $projectName = 'Programme', array $projectAttrs = []): Project
{
    $client = Client::query()->where('name', $clientName)->first()
        ?? Client::factory()->create([
            'name' => $clientName,
            'task_billability_profile' => ClientTaskBillabilityProfile::Jdw,
        ]);

    return Project::factory()->create(array_merge(['client_id' => $client->id, 'name' => $projectName], $projectAttrs));
}

function auditTask(string $name = 'Development'): Task
{
    return Task::query()->where('name', $name)->first() ?? Task::factory()->create(['name' => $name]);
}

function auditEntry(User $user, Project $project, Task $task, string $spentOn, float $hours, array $attrs = []): TimeEntry
{
    return TimeEntry::create(array_merge([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'spent_on' => $spentOn,
        'hours' => $hours,
        'notes' => null,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => round($hours * 84.0, 2),
    ], $attrs));
}

function auditPosition(User $user, ?ContractPosition $position, string $effectiveFrom): void
{
    UserContractPosition::create([
        'user_id' => $user->id,
        'position' => $position,
        'effective_from' => $effectiveFrom,
    ]);
}

/** @param  array<int, int>  $clientIds */
function auditCsv(array $clientIds, string $from = '2026-07-01', string $to = '2026-07-31'): string
{
    return (new AuditTimeCsvExport(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $clientIds))->toCsv();
}

/** @return list<list<string|null>> the CSV's data rows, header excluded */
function auditDataRows(string $csv): array
{
    $lines = array_filter(explode("\r\n", $csv), fn (string $line): bool => $line !== '');

    return array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_slice(array_values($lines), 1));
}

function auditStreamBody($response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('freezes audit export headers', function () {
    $project = auditProject();

    $firstLine = explode("\r\n", auditCsv([$project->client_id]))[0];

    expect($firstLine)->toBe(AUDIT_HEADER);
    expect(AuditTimeCsvExport::HEADERS)->toBe(explode(',', AUDIT_HEADER));
});

it('aggregates to name, role, position, month, client, project and task', function () {
    $user = auditUser();
    auditPosition($user, ContractPosition::Developer, '2026-01-01');
    $project = auditProject();
    $task = auditTask();
    auditEntry($user, $project, $task, '2026-07-06', 1.5);
    auditEntry($user, $project, $task, '2026-07-21', 2.0);
    auditEntry($user, $project, $task, '2026-08-03', 1.0);

    $csv = auditCsv([$project->client_id], '2026-07-01', '2026-08-31');

    expect(auditDataRows($csv))->toBe([
        ['Alice Smith', 'Developer', 'Developer', '2026-07', 'JDW Management', 'Programme', 'Development', '3.50'],
        ['Alice Smith', 'Developer', 'Developer', '2026-08', 'JDW Management', 'Programme', 'Development', '1.00'],
    ]);
    expect($csv)->toEndWith("\r\n")->not->toContain("\n\n");
});

it('exposes the folded rows keyed by header with hours as a float', function () {
    $user = auditUser();
    $project = auditProject();
    auditEntry($user, $project, auditTask(), '2026-07-06', 1.1);
    auditEntry($user, $project, auditTask(), '2026-07-07', 2.2);

    $rows = (new AuditTimeCsvExport(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), [$project->client_id]))->rows();

    expect($rows)->toHaveCount(1);
    expect(array_keys($rows->first()))->toBe(AuditTimeCsvExport::HEADERS);
    expect($rows->first()['Hours'])->toBe(3.3);
});

it('splits a month when the contract position changes mid-month', function () {
    $user = auditUser();
    auditPosition($user, ContractPosition::Developer, '2026-01-01');
    auditPosition($user, ContractPosition::SeniorDeveloper, '2026-07-16');
    $project = auditProject();
    $task = auditTask();
    auditEntry($user, $project, $task, '2026-07-15', 2.0);
    auditEntry($user, $project, $task, '2026-07-16', 3.0);

    $rows = auditDataRows(auditCsv([$project->client_id]));

    expect($rows)->toBe([
        ['Alice Smith', 'Developer', 'Developer', '2026-07', 'JDW Management', 'Programme', 'Development', '2.00'],
        ['Alice Smith', 'Developer', 'Senior Developer', '2026-07', 'JDW Management', 'Programme', 'Development', '3.00'],
    ]);
});

it('labels users without a position as outside contracted team', function () {
    $user = auditUser();
    $project = auditProject();
    auditEntry($user, $project, auditTask(), '2026-07-06', 1.0);

    expect(auditDataRows(auditCsv([$project->client_id]))[0][2])->toBe('Outside contracted team');
});

it('labels a user moved outside the team as outside contracted team', function () {
    $user = auditUser();
    auditPosition($user, ContractPosition::Developer, '2026-01-01');
    auditPosition($user, null, '2026-07-10');
    $project = auditProject();
    auditEntry($user, $project, auditTask(), '2026-07-06', 1.0);
    auditEntry($user, $project, auditTask(), '2026-07-10', 1.0);

    expect(array_column(auditDataRows(auditCsv([$project->client_id])), 2))
        ->toBe(['Developer', 'Outside contracted team']);
});

it('writes an empty filter role for a user without a role title', function () {
    $project = auditProject();
    auditEntry(auditUser('Alice Smith', null), $project, auditTask(), '2026-07-06', 1.0);

    expect(auditDataRows(auditCsv([$project->client_id]))[0][1])->toBe('');
});

it('quotes names that contain commas', function () {
    $project = auditProject('Acme, Inc', 'Site "v2"');
    auditEntry(auditUser(), $project, auditTask(), '2026-07-06', 1.0);

    $csv = auditCsv([$project->client_id]);

    expect($csv)->toContain('"Acme, Inc","Site ""v2"""');
    expect(auditDataRows($csv)[0][4])->toBe('Acme, Inc');
});

it('neutralises text that a spreadsheet would run as a formula', function () {
    $project = auditProject('=Client', '=HYPERLINK("x")');
    auditEntry(auditUser('@Alice Smith', '+Developer'), $project, auditTask('-Discovery'), '2026-07-06', 1.0);

    $csv = auditCsv([$project->client_id]);

    expect($csv)->toContain('\'@Alice Smith,\'+Developer,Outside contracted team,2026-07,\'=Client,"\'=HYPERLINK(""x"")",\'-Discovery,1.00');
    expect(auditDataRows($csv)[0])->toBe([
        "'@Alice Smith", "'+Developer", 'Outside contracted team', '2026-07', "'=Client", "'=HYPERLINK(\"x\")", "'-Discovery", '1.00',
    ]);
});

it('never includes notes, rates or amounts', function () {
    $project = auditProject();
    auditEntry(auditUser(), $project, auditTask(), '2026-07-06', 2.0, [
        'notes' => 'secret note',
        'billable_rate_snapshot' => 84.0,
        'billable_amount' => 168.0,
    ]);

    $csv = auditCsv([$project->client_id]);

    expect($csv)->toContain('2.00')
        ->not->toContain('secret note')
        ->not->toContain('84.0')
        ->not->toContain('168.0');
});

it('sorts by name, month, client, project and task in byte order', function () {
    $zed = auditUser('zed Young');
    $amy = auditUser('Amy Adams');
    $bob = auditUser('bob Brown');
    $a = auditProject('Beta Client', 'Alpha');
    $b = auditProject('alpha Client', 'Zulu');
    $c = auditProject('alpha Client', 'yankee');
    $dev = auditTask('Development');
    $qa = auditTask('analysis');
    foreach ([[$zed, $a, $dev, '2026-07-02'], [$bob, $a, $dev, '2026-07-02'], [$amy, $a, $dev, '2026-08-02'],
        [$amy, $a, $dev, '2026-07-02'], [$amy, $a, $qa, '2026-07-02'], [$amy, $b, $dev, '2026-07-02'],
        [$amy, $c, $dev, '2026-07-02']] as [$user, $project, $task, $day]) {
        auditEntry($user, $project, $task, $day, 1.0);
    }

    $rows = auditDataRows(auditCsv([$a->client_id, $b->client_id], '2026-07-01', '2026-08-31'));

    // Capitals sort before lower case, as in the October sheet.
    expect(array_map(fn (array $row): string => implode('|', [$row[0], $row[3], $row[4], $row[5], $row[6]]), $rows))->toBe([
        'Amy Adams|2026-07|Beta Client|Alpha|Development',
        'Amy Adams|2026-07|Beta Client|Alpha|analysis',
        'Amy Adams|2026-07|alpha Client|Zulu|Development',
        'Amy Adams|2026-07|alpha Client|yankee|Development',
        'Amy Adams|2026-08|Beta Client|Alpha|Development',
        'bob Brown|2026-07|Beta Client|Alpha|Development',
        'zed Young|2026-07|Beta Client|Alpha|Development',
    ]);
});

it('sorts myIntegration after Suggested Rotas', function () {
    $user = auditUser();
    $task = auditTask();
    $mine = auditProject('JDW Management', 'myIntegration');
    $rotas = auditProject('JDW Management', 'Suggested Rotas');
    auditEntry($user, $mine, $task, '2026-07-02', 1.0);
    auditEntry($user, $rotas, $task, '2026-07-02', 1.0);

    expect(array_column(auditDataRows(auditCsv([$mine->client_id])), 5))->toBe(['Suggested Rotas', 'myIntegration']);
});

it('breaks ties on filter role and position so the order never varies', function () {
    $user = auditUser('Alice Smith', 'Developer');
    auditPosition($user, ContractPosition::Developer, '2026-01-01');
    auditPosition($user, ContractPosition::SeniorDeveloper, '2026-07-16');
    $project = auditProject();
    // Booked newest first, so insertion order cannot be what produces the result.
    auditEntry($user, $project, auditTask(), '2026-07-20', 1.0);
    auditEntry($user, $project, auditTask(), '2026-07-10', 1.0);

    expect(array_column(auditDataRows(auditCsv([$project->client_id])), 2))->toBe(['Developer', 'Senior Developer']);
});

it('writes no rows for entries that add up to nothing', function () {
    $user = auditUser();
    $project = auditProject();
    auditEntry($user, $project, auditTask('Idle'), '2026-07-06', 0.0);
    auditEntry($user, $project, auditTask('Development'), '2026-07-06', 0.0);
    auditEntry($user, $project, auditTask('Development'), '2026-07-07', 2.0);

    $rows = auditDataRows(auditCsv([$project->client_id]));

    expect(array_column($rows, 6))->toBe(['Development']);
    expect(array_column($rows, 7))->toBe(['2.00']);
});

it('writes no rows for a client whose only entries are zero hours', function () {
    $project = auditProject();
    auditEntry(auditUser(), $project, auditTask(), '2026-07-06', 0.0);

    expect(auditCsv([$project->client_id]))->toBe(AUDIT_HEADER."\r\n");
});

it('includes the first and last day of the range and nothing outside it', function () {
    $user = auditUser();
    $project = auditProject();
    $task = auditTask();
    auditEntry($user, $project, $task, '2026-06-30', 1.0);
    auditEntry($user, $project, $task, '2026-07-01', 2.0);
    auditEntry($user, $project, $task, '2026-07-31', 4.0);
    auditEntry($user, $project, $task, '2026-08-01', 8.0);

    $rows = auditDataRows(auditCsv([$project->client_id]));

    expect($rows)->toHaveCount(1)->and($rows[0][7])->toBe('6.00');
});

it('includes archived projects and only the chosen clients', function () {
    $user = auditUser();
    $task = auditTask();
    $live = auditProject('JDW Management', 'Live');
    $archived = auditProject('JDW Management', 'Archived', ['is_archived' => true]);
    $other = auditProject('Other Client', 'Elsewhere');
    auditEntry($user, $live, $task, '2026-07-06', 1.0);
    auditEntry($user, $archived, $task, '2026-07-06', 2.0);
    auditEntry($user, $other, $task, '2026-07-06', 4.0);

    $rows = auditDataRows(auditCsv([$live->client_id]));

    expect(array_column($rows, 5))->toBe(['Archived', 'Live']);
    expect(array_column($rows, 7))->toBe(['2.00', '1.00']);
});

it('exports no rows when no clients are chosen', function () {
    auditEntry(auditUser(), auditProject(), auditTask(), '2026-07-06', 1.0);

    expect(auditCsv([]))->toBe(AUDIT_HEADER."\r\n");
});

it('matches the time report totals by client for July 2026', function () {
    $alice = auditUser('Alice Smith');
    $bob = auditUser('Bob Jones', 'Designer');
    auditPosition($alice, ContractPosition::Developer, '2026-01-01');
    auditPosition($alice, ContractPosition::SeniorDeveloper, '2026-07-16');
    auditPosition($bob, ContractPosition::UxDesign, '2026-03-01');
    $dev = auditTask('Development');
    $design = auditTask('Design');

    $management = auditProject('JDW Management', 'Programme');
    $managementArchived = auditProject('JDW Management', 'Retired programme', ['is_archived' => true]);
    $support = auditProject('JDW Support & Maintenance', 'Support');
    $agency = auditProject('Agency Client', 'Brochure');
    Client::query()->where('name', 'Agency Client')->update(['task_billability_profile' => ClientTaskBillabilityProfile::Agency]);

    foreach ([
        [$alice, $management, $dev, '2026-07-01', 1.25],
        [$alice, $management, $dev, '2026-07-15', 0.1],
        [$alice, $management, $dev, '2026-07-16', 0.2],
        [$alice, $managementArchived, $dev, '2026-07-20', 3.5],
        [$alice, $support, $design, '2026-07-31', 0.75],
        [$bob, $management, $design, '2026-07-08', 2.5],
        [$bob, $managementArchived, $design, '2026-07-08', 0.25],
        [$bob, $support, $dev, '2026-07-31', 6.0],
        [$bob, $agency, $dev, '2026-07-10', 5.0],
        // Booked but zero: adds nothing to the report, so the audit drops it.
        [$bob, $support, $design, '2026-07-02', 0.0],
        // Outside the range: must not be counted on either side.
        [$alice, $management, $dev, '2026-06-30', 7.0],
        [$bob, $support, $dev, '2026-08-01', 9.0],
    ] as [$user, $project, $task, $day, $hours]) {
        auditEntry($user, $project, $task, $day, $hours);
    }

    $report = (new TimeReportQuery(from: CarbonImmutable::parse('2026-07-01'), to: CarbonImmutable::parse('2026-07-31')))
        ->groupBy(GroupBy::Client)
        ->mapWithKeys(fn (stdClass $row): array => [$row->label => round($row->total_hours, 2)])
        ->sortKeys()
        ->all();

    $audit = (new AuditTimeCsvExport(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
        Client::query()->pluck('id')->all(),
    ))->rows()
        ->groupBy('Client')
        ->map(fn ($rows): float => round((float) $rows->sum('Hours'), 2))
        ->sortKeys()
        ->all();

    expect($audit)->toBe($report);
    expect($audit)->toBe(['Agency Client' => 5.0, 'JDW Management' => 7.8, 'JDW Support & Maintenance' => 6.75]);
});

it('ticks every JDW client by default, archived or not, and no others', function () {
    $jdw = auditProject('JDW Projects', 'Programme');
    $archivedJdw = auditProject('JDW Retired', 'Old');
    $archivedJdw->client->update(['is_archived' => true]);
    $agency = auditProject('Agency Client', 'Brochure');
    $agency->client->update(['task_billability_profile' => ClientTaskBillabilityProfile::Agency]);

    $test = Livewire::actingAs(User::factory()->admin()->create())->test(AuditExport::class);

    expect(array_map('intval', $test->get('clientIds')))->toBe([$jdw->client_id, $archivedJdw->client_id]);
});

it('is forbidden to standard users and open to managers and admins', function () {
    $this->actingAs(User::factory()->create())->get('/reports/audit-export')->assertForbidden();

    foreach ([User::factory()->manager()->create(), User::factory()->admin()->create()] as $user) {
        $this->actingAs($user)
            ->get('/reports/audit-export')
            ->assertOk()
            ->assertSee('Audit export')
            ->assertSee('One row per person, month, client, project and task. No notes, rates or amounts.')
            ->assertSee('href="'.route('reports.audit-export').'"', false);
    }
});

it('defaults to last month and lists every client by name, labelling archived ones', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00'));
    auditProject('Zulu Client', 'One');
    auditProject('Alpha Client', 'Two')->client->update(['task_billability_profile' => ClientTaskBillabilityProfile::Agency]);
    auditProject('Retired Client', 'Three')->client->update(['is_archived' => true]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->assertSet('from', '2026-09-01')
        ->assertSet('to', '2026-09-30')
        ->assertSeeInOrder(['Alpha Client', 'Retired Client', '(archived)', 'Zulu Client'])
        ->assertSeeHtml('value="'.Client::query()->where('name', 'Alpha Client')->value('id').'"');
});

it('lists a non-JDW client without ticking it, and ticks an archived JDW client', function () {
    $archivedJdw = auditProject('JDW Retired', 'Old');
    $archivedJdw->client->update(['is_archived' => true]);
    $agency = auditProject('Agency Client', 'Brochure');
    $agency->client->update(['task_billability_profile' => ClientTaskBillabilityProfile::Agency]);

    $html = Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->assertSee('Agency Client')
        ->assertSee('JDW Retired')
        ->html();

    $checkbox = fn (Client $client): string => 'wire:model.live="clientIds" value="'.$client->id.'"';

    expect($html)->toContain($checkbox($archivedJdw->client))->toContain($checkbox($agency->client));
    expect(Livewire::actingAs(User::factory()->admin()->create())->test(AuditExport::class)->get('clientIds'))
        ->toContain($archivedJdw->client_id)
        ->not->toContain($agency->client_id);
});

it('exports an archived JDW client\'s hours with the default selection', function () {
    $live = auditProject('JDW Management', 'Programme');
    $archived = auditProject('JDW Retired', 'Old programme');
    $archived->client->update(['is_archived' => true]);
    $agency = auditProject('Agency Client', 'Brochure');
    $agency->client->update(['task_billability_profile' => ClientTaskBillabilityProfile::Agency]);
    $user = auditUser();
    $task = auditTask();
    auditEntry($user, $live, $task, '2026-07-06', 1.0);
    auditEntry($user, $archived, $task, '2026-07-06', 2.0);
    auditEntry($user, $agency, $task, '2026-07-06', 4.0);

    $test = Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31');
    $rows = auditDataRows(auditStreamBody($test->instance()->download()));

    expect(array_column($rows, 4))->toBe(['JDW Management', 'JDW Retired']);
    expect(array_column($rows, 7))->toBe(['1.00', '2.00']);
});

it('defaults to the previous calendar month at the end of a long month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->assertSet('from', '2026-02-01')
        ->assertSet('to', '2026-02-28');
});

it('downloads the audit CSV named after its date range', function () {
    $user = auditUser();
    $project = auditProject();
    auditEntry($user, $project, auditTask(), '2026-07-06', 1.5, ['notes' => 'secret note']);
    auditEntry($user, $project, auditTask(), '2026-08-06', 4.0);

    $test = Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31')
        ->set('clientIds', [$project->client_id])
        ->call('download')
        ->assertHasNoErrors()
        ->assertFileDownloaded('audit-time-2026-07-01-to-2026-07-31.csv');

    $response = $test->instance()->download();
    $body = auditStreamBody($response);

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8');
    expect($body)->toBe(AUDIT_HEADER."\r\nAlice Smith,Developer,Outside contracted team,2026-07,JDW Management,Programme,Development,1.50\r\n");
});

it('refuses to download an invalid request', function (array $set, string $errorKey) {
    $client = auditProject()->client;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->set(array_merge(['from' => '2026-07-01', 'to' => '2026-07-31', 'clientIds' => [$client->id]], $set))
        ->call('download')
        ->assertHasErrors($errorKey)
        ->assertNoFileDownloaded();
})->with([
    'no from date' => [['from' => ''], 'from'],
    'no to date' => [['to' => ''], 'to'],
    'to before from' => [['to' => '2026-06-30'], 'to'],
    'no clients' => [['clientIds' => []], 'clientIds'],
]);

it('accepts a one-day range', function () {
    $client = auditProject()->client;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->set(['from' => '2026-07-10', 'to' => '2026-07-10', 'clientIds' => [$client->id]])
        ->call('download')
        ->assertHasNoErrors()
        ->assertFileDownloaded('audit-time-2026-07-10-to-2026-07-10.csv');
});

it('shows validation errors as soon as the form is edited', function () {
    $client = auditProject()->client;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->set(['from' => '2026-07-01', 'to' => '2026-07-31', 'clientIds' => [$client->id]])
        ->set('to', '2026-06-01')
        ->assertSee('The end date must be on or after the start date.')
        ->set('to', '2026-07-31')
        ->assertDontSee('The end date must be on or after the start date.')
        ->set('clientIds', [])
        ->assertSee('Pick at least one client.')
        ->set('clientIds', [$client->id])
        ->assertDontSee('Pick at least one client.');
});

it('only flags the field that was edited', function () {
    $client = auditProject()->client;
    $form = Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->set(['from' => '2026-07-01', 'to' => '2026-07-31', 'clientIds' => [$client->id]]);

    $form->set('from', '')
        ->assertHasErrors(['from'])
        ->assertHasNoErrors(['to', 'clientIds'])
        ->set('from', '2026-07-01')
        ->assertHasNoErrors()
        ->set('to', '')
        ->assertHasErrors(['to'])
        ->assertHasNoErrors(['from', 'clientIds'])
        ->set('to', '2026-07-31')
        ->set('clientIds', [])
        ->assertHasErrors(['clientIds'])
        ->assertHasNoErrors(['from', 'to']);
});

it('flags the end date when the start date is moved past it, and clears it when moved back', function () {
    $client = auditProject()->client;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(AuditExport::class)
        ->set(['from' => '2026-07-01', 'to' => '2026-07-31', 'clientIds' => [$client->id]])
        ->set('from', '2026-08-15')
        ->assertHasErrors(['to'])
        ->assertHasNoErrors(['from'])
        ->set('from', '2026-07-15')
        ->assertHasNoErrors(['from', 'to']);
});
