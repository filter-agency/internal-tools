<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

const EMPLOYEE_MATRIX_TEST_HEADER = ['First name', 'Surname', 'Join date', 'Status', 'Person type'];

afterEach(function () {
    foreach (glob(storage_path('framework/testing/employee-matrix-*.xlsx')) ?: [] as $file) {
        unlink($file);
    }
});

/**
 * Builds a workbook in the shape of the HR sheet: title row, legend row, year row,
 * header row, one row per person and a totals row with no names.
 *
 * Each person: first, surname, join (DateTime or serial number for a real date cell,
 * string for text), status, type and marks (month as Y-m => marker).
 *
 * $monthShift moves the first month column on from February (1 = March) and
 * $firstYearLabel is the year label over that column, to build mis-laid-out sheets.
 *
 * @param  array<int, array<string, mixed>>  $people
 * @param  array<int, string>|null  $header
 */
function employeeMatrixWorkbook(array $people, ?array $header = null, int $monthShift = 0, string $firstYearLabel = '2024'): string
{
    $months = ['Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan'];

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Employee Matrix');
    $sheet->setCellValue([1, 1], 'EMPLOYEE MOVEMENT & HEADCOUNT MATRIX');
    $sheet->setCellValue([1, 2], 'X = joined, ● = employed, p = leave month');
    $sheet->setCellValue([6, 3], $firstYearLabel);
    $sheet->setCellValue([17, 3], "'2025'");
    $sheet->setCellValue([29, 3], "'2026'");

    // Header row (sheet row 4), then 33 month columns from Feb 2024 to Oct 2026.
    foreach ($header ?? EMPLOYEE_MATRIX_TEST_HEADER as $column => $label) {
        $sheet->setCellValue([$column + 1, 4], $label);
    }
    for ($i = 0; $i < 33; $i++) {
        $sheet->setCellValue([6 + $i, 4], $months[($i + $monthShift) % 12]);
    }

    $row = 5;
    foreach ($people as $person) {
        $sheet->setCellValue([1, $row], $person['first']);
        $sheet->setCellValue([2, $row], $person['surname']);

        $join = $person['join'];
        if ($join instanceof DateTimeInterface) {
            $join = ExcelDate::PHPToExcel($join);
        }
        if (is_string($join)) {
            $sheet->setCellValueExplicit([3, $row], $join, DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue([3, $row], $join);
            $sheet->getStyle([3, $row])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        }

        $sheet->setCellValue([4, $row], $person['status']);
        $sheet->setCellValue([5, $row], $person['type']);

        foreach ($person['marks'] ?? [] as $month => $marker) {
            [$year, $number] = array_map('intval', explode('-', $month));
            $offset = ($year - 2024) * 12 + ($number - 2);
            $sheet->setCellValue([6 + $offset, $row], $marker);
        }
        $row++;
    }

    // Totals row: no names, integer headcounts in the month columns.
    $sheet->setCellValue([6, $row], 24);
    $sheet->setCellValue([7, $row], 27);
    $sheet->setCellValue([38, $row], 31);

    $path = storage_path('framework/testing/employee-matrix-'.uniqid().'.xlsx');
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function employeeMatrixRun(string $path, array $options = []): array
{
    $exitCode = Artisan::call('users:import-employee-matrix', ['path' => $path] + $options);

    return [$exitCode, Artisan::output()];
}

/** @param  array<string, mixed>  $overrides */
function employeeMatrixPerson(array $overrides = []): array
{
    return $overrides + [
        'first' => 'Jane',
        'surname' => 'Doe',
        'join' => new DateTimeImmutable('2025-03-10'),
        'status' => 'Current employee',
        'type' => 'Employee',
        'marks' => [],
    ];
}

it('sets start date, leave date and person type for matched users', function () {
    // Leavers are archived, so matching must not skip archived users.
    $jane = User::factory()->create(['name' => 'Jane Doe', 'is_contractor' => false, 'is_active' => false, 'archived_at' => now()]);
    $other = User::factory()->create(['name' => 'Someone Else', 'is_contractor' => false]);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson([
            'status' => 'Ex-employee',
            'type' => 'Contractor',
            'marks' => ['2025-03' => 'X', '2025-04' => '●', '2026-05' => '●', '2026-06' => 'p'],
        ]),
    ]);

    [$exitCode, $output] = employeeMatrixRun($path);

    expect($exitCode)->toBe(0);

    $jane->refresh();
    expect($jane->started_on?->toDateString())->toBe('2025-03-10')
        ->and($jane->left_on?->toDateString())->toBe('2026-06-30')
        ->and($jane->is_contractor)->toBeTrue()
        ->and($other->fresh()->started_on)->toBeNull();

    expect($output)->toContain('Jane Doe')
        ->toContain('2025-03-10')
        ->toContain('2026-06-30')
        ->toContain('Contractor')
        ->toContain('Updated: 1')
        ->toContain('Approximated leave dates')
        ->not->toContain('Dry run');
});

it('lists unmatched names and writes nothing on dry run', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe', 'is_contractor' => false]);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson([
            'status' => 'Ex-employee',
            'type' => 'Contractor',
            'marks' => ['2026-06' => 'p'],
        ]),
        employeeMatrixPerson(['first' => 'Nobody', 'surname' => 'Known']),
    ]);

    [$exitCode, $output] = employeeMatrixRun($path, ['--dry-run' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Dry run: nothing written.')
        ->toContain('Unmatched: 1')
        ->toContain('Nobody Known')
        ->toContain('Jane Doe')
        ->toContain('2026-06-30');

    $jane->refresh();
    expect($jane->started_on)->toBeNull()
        ->and($jane->left_on)->toBeNull()
        ->and($jane->is_contractor)->toBeFalse();
});

it('fails with a clear message and writes nothing when the header does not match', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook(
        [employeeMatrixPerson()],
        ['First name', 'Surname', 'Start date', 'Status', 'Person type'],
    );

    [$exitCode, $output] = employeeMatrixRun($path);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Join date')
        ->toContain('Start date')
        ->and($jane->fresh()->started_on)->toBeNull();
});

it('fails when there is no header row', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([employeeMatrixPerson()], ['Forename', 'Surname', 'Join date', 'Status', 'Person type']);

    [$exitCode, $output] = employeeMatrixRun($path);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('First name')
        ->and($jane->fresh()->started_on)->toBeNull();
});

it('fails when the file does not exist', function () {
    [$exitCode, $output] = employeeMatrixRun(storage_path('framework/testing/employee-matrix-missing.xlsx'));

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('File not found');
});

it('leaves the leave date alone and lists an ex-employee with no leave month', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe', 'is_contractor' => true, 'left_on' => '2026-01-31']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['status' => 'Ex-employee', 'type' => 'Employee', 'marks' => ['2025-03' => 'X']]),
    ]);

    [$exitCode, $output] = employeeMatrixRun($path);

    $jane->refresh();
    expect($exitCode)->toBe(0)
        ->and($jane->started_on?->toDateString())->toBe('2025-03-10')
        ->and($jane->is_contractor)->toBeFalse()
        ->and($jane->left_on?->toDateString())->toBe('2026-01-31')
        ->and($output)->toContain('no leave month found')
        ->toContain('Jane Doe')
        ->not->toContain('Approximated leave dates');
});

it('does not set a leave date for a current employee with a leave marker and lists them', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['status' => 'Current employee', 'marks' => ['2026-06' => 'p']]),
    ]);

    [$exitCode, $output] = employeeMatrixRun($path);

    $jane->refresh();
    expect($exitCode)->toBe(0)
        ->and($jane->started_on?->toDateString())->toBe('2025-03-10')
        ->and($jane->left_on)->toBeNull()
        ->and($output)->toContain('current employee with a leave marker')
        ->toContain('Jane Doe')
        ->toContain('Jun 2026')
        ->not->toContain('Approximated leave dates');
});

it('never clears a leave date that it could not determine', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe', 'left_on' => '2026-02-28']);

    $path = employeeMatrixWorkbook([employeeMatrixPerson(['status' => 'Current employee'])]);

    employeeMatrixRun($path);

    expect($jane->fresh()->left_on?->toDateString())->toBe('2026-02-28');
});

it('uses the end of the last month marked p', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['status' => 'Ex-employee', 'marks' => ['2025-02' => 'p', '2025-10' => 'P', '2024-02' => 'p']]),
    ]);

    employeeMatrixRun($path);

    // Last p is Oct 2025; a February marker would end on the 28th or 29th.
    expect($jane->fresh()->left_on?->toDateString())->toBe('2025-10-31');
});

it('finds a leap-year leave month end', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['join' => new DateTimeImmutable('2024-02-01'), 'status' => 'Ex-employee', 'marks' => ['2024-02' => 'p']]),
    ]);

    employeeMatrixRun($path);

    expect($jane->fresh()->left_on?->toDateString())->toBe('2024-02-29');
});

it('skips the totals row', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([employeeMatrixPerson()]);

    [$exitCode, $output] = employeeMatrixRun($path);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Updated: 1')
        ->toContain('Unmatched: 0')
        ->toContain('Skipped rows: 0')
        ->and($jane->fresh()->started_on?->toDateString())->toBe('2025-03-10');
});

it('reads join dates given as a serial number and as strings', function () {
    $serial = User::factory()->create(['name' => 'Serial Person']);
    $iso = User::factory()->create(['name' => 'Iso Person']);
    $uk = User::factory()->create(['name' => 'Uk Person']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['first' => 'Serial', 'surname' => 'Person', 'join' => 45726]),
        employeeMatrixPerson(['first' => 'Iso', 'surname' => 'Person', 'join' => '2025-04-01']),
        employeeMatrixPerson(['first' => 'Uk', 'surname' => 'Person', 'join' => '15/05/2025']),
    ]);

    [$exitCode] = employeeMatrixRun($path);

    expect($exitCode)->toBe(0)
        ->and($serial->fresh()->started_on?->toDateString())->toBe('2025-03-10')
        ->and($iso->fresh()->started_on?->toDateString())->toBe('2025-04-01')
        ->and($uk->fresh()->started_on?->toDateString())->toBe('2025-05-15');
});

it('reports and skips rows it cannot read', function () {
    $badDate = User::factory()->create(['name' => 'Bad Date']);
    $badStatus = User::factory()->create(['name' => 'Bad Status']);
    $badType = User::factory()->create(['name' => 'Bad Type']);
    $impossible = User::factory()->create(['name' => 'Impossible Date']);
    $good = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['first' => 'Bad', 'surname' => 'Date', 'join' => 'next week']),
        employeeMatrixPerson(['first' => 'Bad', 'surname' => 'Status', 'status' => 'On sabbatical']),
        employeeMatrixPerson(['first' => 'Bad', 'surname' => 'Type', 'type' => 'Freelancer']),
        employeeMatrixPerson(['first' => 'Impossible', 'surname' => 'Date', 'join' => '31/02/2025']),
        employeeMatrixPerson(),
    ]);

    [$exitCode, $output] = employeeMatrixRun($path);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Skipped rows: 4')
        ->toContain('Bad Date')
        ->toContain('next week')
        ->toContain('On sabbatical')
        ->toContain('Freelancer')
        ->toContain('31/02/2025')
        ->and($badDate->fresh()->started_on)->toBeNull()
        ->and($badStatus->fresh()->started_on)->toBeNull()
        ->and($badType->fresh()->started_on)->toBeNull()
        ->and($impossible->fresh()->started_on)->toBeNull()
        ->and($good->fresh()->started_on?->toDateString())->toBe('2025-03-10');
});

it('matches names ignoring case and extra whitespace', function () {
    $jane = User::factory()->create(['name' => '  jane   DOE ']);

    $path = employeeMatrixWorkbook([employeeMatrixPerson(['first' => ' Jane ', 'surname' => 'Doe'])]);

    employeeMatrixRun($path);

    expect($jane->fresh()->started_on?->toDateString())->toBe('2025-03-10');
});

it('reports names that match more than one user and skips them', function () {
    $first = User::factory()->create(['name' => 'Jane Doe']);
    $second = User::factory()->create(['name' => 'jane doe']);

    $path = employeeMatrixWorkbook([employeeMatrixPerson()]);

    [$exitCode, $output] = employeeMatrixRun($path);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Ambiguous: 1')
        ->toContain('Jane Doe')
        ->toContain('Updated: 0')
        ->and($first->fresh()->started_on)->toBeNull()
        ->and($second->fresh()->started_on)->toBeNull();
});

it('is safe to run twice', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['status' => 'Ex-employee', 'type' => 'Contractor', 'marks' => ['2026-06' => 'p']]),
    ]);

    employeeMatrixRun($path);
    [, $output] = employeeMatrixRun($path);

    expect($output)->toContain('Updated: 0')
        ->toContain('Already up to date: 1')
        ->and($jane->fresh()->left_on?->toDateString())->toBe('2026-06-30');
});

it('writes nothing for a person listed on more than one row and lists them once', function () {
    $rejoiner = User::factory()->create(['name' => 'Jane Doe']);
    $other = User::factory()->create(['name' => 'John Smith']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson([
            'join' => new DateTimeImmutable('2024-03-04'),
            'status' => 'Ex-employee',
            'marks' => ['2025-06' => 'p'],
        ]),
        employeeMatrixPerson(['first' => 'John', 'surname' => 'Smith']),
        employeeMatrixPerson([
            'first' => 'jane',
            'surname' => 'DOE',
            'join' => new DateTimeImmutable('2025-09-01'),
            'status' => 'Current employee',
        ]),
    ]);

    [$exitCode, $output] = employeeMatrixRun($path);

    $rejoiner->refresh();
    expect($exitCode)->toBe(0)
        ->and($rejoiner->started_on)->toBeNull()
        ->and($rejoiner->left_on)->toBeNull()
        ->and($other->fresh()->started_on?->toDateString())->toBe('2025-03-10')
        ->and($output)->toContain('Listed more than once (not updated)')
        ->toContain('Jane Doe (rows 5, 7)')
        ->toContain('Updated: 1')
        ->toContain('Listed more than once: 1')
        ->not->toContain('Approximated leave dates')
        ->and(substr_count($output, 'Jane Doe'))->toBe(1);
});

it('does not write a leave month that falls before the join month', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['status' => 'Ex-employee', 'type' => 'Contractor', 'marks' => ['2025-02' => 'p']]),
    ]);

    [$exitCode, $output] = employeeMatrixRun($path);

    $jane->refresh();
    expect($exitCode)->toBe(0)
        ->and($jane->started_on)->toBeNull()
        ->and($jane->left_on)->toBeNull()
        ->and($jane->is_contractor)->toBeFalse()
        ->and($output)->toContain('Leave date before start date (not updated)')
        ->toContain('Jane Doe')
        ->toContain('2025-02-28')
        ->toContain('2025-03-10')
        ->toContain('Updated: 0')
        ->toContain('Leave before start: 1')
        ->not->toContain('Approximated leave dates');
});

it('allows a leave month that is the same month as the join date', function () {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([
        employeeMatrixPerson(['status' => 'Ex-employee', 'marks' => ['2025-03' => 'p']]),
    ]);

    employeeMatrixRun($path);

    expect($jane->fresh()->left_on?->toDateString())->toBe('2025-03-31');
});

it('checks the new start date against a stored leave date when the sheet sets none', function () {
    $rejoiner = User::factory()->create(['name' => 'Jane Doe', 'left_on' => '2025-01-31']);

    $path = employeeMatrixWorkbook([employeeMatrixPerson(['status' => 'Current employee'])]);

    [, $output] = employeeMatrixRun($path);

    $rejoiner->refresh();
    expect($rejoiner->started_on)->toBeNull()
        ->and($rejoiner->left_on?->toDateString())->toBe('2025-01-31')
        ->and($output)->toContain('Leave date before start date (not updated)')
        ->toContain('2025-01-31')
        ->toContain('2025-03-10')
        ->toContain('Updated: 0');
});

it('fails and writes nothing when the month columns do not start at February 2024', function (int $monthShift, string $yearLabel) {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook(
        [employeeMatrixPerson(['status' => 'Ex-employee', 'marks' => ['2026-06' => 'p']])],
        null,
        $monthShift,
        $yearLabel,
    );

    [$exitCode, $output] = employeeMatrixRun($path);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("Month columns don't start at Feb 2024; check the sheet layout.")
        ->and($jane->fresh()->started_on)->toBeNull()
        ->and($jane->fresh()->left_on)->toBeNull();
})->with([
    'first month is Mar' => [1, '2024'],
    'first year is 2025' => [0, '2025'],
]);

it('accepts the first year label as a number or as text with apostrophes', function (mixed $yearLabel) {
    $jane = User::factory()->create(['name' => 'Jane Doe']);

    $path = employeeMatrixWorkbook([employeeMatrixPerson()], null, 0, $yearLabel);

    [$exitCode] = employeeMatrixRun($path);

    expect($exitCode)->toBe(0)
        ->and($jane->fresh()->started_on?->toDateString())->toBe('2025-03-10');
})->with([
    'numeric cell' => ['2024'],
    'text with apostrophes' => ["'2024'"],
]);
