<?php

namespace App\Console\Commands;

use App\Domain\Reporting\CsvFormatter;
use App\Enums\ClientTaskBillabilityProfile;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HarvestImport extends Command
{
    protected $signature = 'harvest:import
        {path : Path to the Harvest detailed-time CSV file}
        {--dry-run : Parse and validate without writing to the database}
        {--since= : Only import entries on or after this date (YYYY-MM-DD)}';

    protected $description = 'Import a Harvest detailed-time CSV export into time_entries';

    /** @var array<string, int> */
    private array $clientCache = [];

    /** @var array<string, int> */
    private array $projectCache = [];

    /** @var array<string, int> */
    private array $taskCache = [];

    /** @var array<string, int> */
    private array $userCache = [];

    /** @var array<string, true> */
    private array $userMissCache = [];

    private int $imported = 0;

    private int $skipped = 0;

    private int $errors = 0;

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;

        if ($dryRun) {
            $this->info('[dry-run] No changes will be written.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Cannot open file: {$path}");

            return self::FAILURE;
        }

        // Skip header row
        fgetcsv($handle);

        $bar = $this->output->createProgressBar();
        $bar->start();

        while (($row = fgetcsv($handle)) !== false) {
            $bar->advance();

            if (count($row) < 21) {
                $this->errors++;

                continue;
            }

            try {
                $this->processRow($row, $dryRun, $since);
            } catch (\RuntimeException $e) {
                $bar->clear();
                $this->error($e->getMessage());
                $bar->display();
                $this->errors++;
            }
        }

        fclose($handle);
        $bar->finish();
        $this->newLine();

        $this->info("Done. Imported: {$this->imported}, Skipped (duplicates): {$this->skipped}, Errors: {$this->errors}");

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function processRow(array $row, bool $dryRun, ?Carbon $since): void
    {
        [
            $date, $clientName, $projectName, $projectCode, $taskName, $notes,
            $hours, $billableStr, , , $firstName, $lastName, , $roleTitle, $employeeStr,
            $billableRate, $billableAmount, , , , $externalRefUrl
        ] = $row;

        // Our own detailed export prefixes formula-like text with a quote
        // (CsvFormatter::text()); drop it so a re-import restores the value.
        [$clientName, $projectName, $projectCode, $taskName, $notes, $firstName, $lastName, $roleTitle, $externalRefUrl] = array_map(
            CsvFormatter::fromText(...),
            [$clientName, $projectName, $projectCode, $taskName, $notes, $firstName, $lastName, $roleTitle, $externalRefUrl],
        );

        if ($since && Carbon::parse($date)->lt($since)) {
            return;
        }

        // Extract numeric Harvest ID from external reference URL
        $externalRef = null;
        if (! empty($externalRefUrl)) {
            if (preg_match('/\/time_entries\/(\d+)/', $externalRefUrl, $matches)) {
                $externalRef = $matches[1];
            } else {
                $externalRef = $externalRefUrl;
            }
        }

        // Deduplicate on external_reference
        if ($externalRef !== null && TimeEntry::where('external_reference', $externalRef)->exists()) {
            $this->skipped++;

            return;
        }

        // Resolve user by email — we match using name since Harvest CSV has no email
        $fullName = trim($firstName.' '.$lastName);
        $userId = $this->resolveUser($fullName, $roleTitle, $employeeStr);

        $clientId = $this->resolveClient($clientName, $dryRun);
        $projectId = $this->resolveProject($projectName, $projectCode, $clientId, $dryRun);
        $taskId = $this->resolveTask($taskName, $dryRun);

        if ($dryRun) {
            $this->imported++;

            return;
        }

        $isBillable = strtolower($billableStr) === 'yes';
        $rateSnapshot = $isBillable && is_numeric($billableRate) ? (float) $billableRate : null;
        $amount = $isBillable && is_numeric($billableAmount) ? (float) $billableAmount : 0.0;

        TimeEntry::create([
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'spent_on' => $date,
            'hours' => (float) $hours,
            'notes' => $notes !== '' ? $notes : null,
            'is_running' => false,
            'is_billable' => $isBillable,
            'billable_rate_snapshot' => $rateSnapshot,
            'billable_amount' => $amount,
            'external_reference' => $externalRef,
        ]);

        $this->imported++;
    }

    private function resolveUser(string $name, string $roleTitle, string $employeeStr): int
    {
        if (isset($this->userCache[$name])) {
            return $this->userCache[$name];
        }

        if (isset($this->userMissCache[$name])) {
            throw new \RuntimeException("No user found with name \"{$name}\". Create the user first or check the name matches exactly.");
        }

        $user = User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if ($user === null) {
            $this->userMissCache[$name] = true;
            throw new \RuntimeException("No user found with name \"{$name}\". Create the user first or check the name matches exactly.");
        }

        // Update role_title if not set
        if ($roleTitle !== '' && $user->role_title === null) {
            $user->update(['role_title' => $roleTitle]);
        }

        $this->userCache[$name] = $user->id;

        return $user->id;
    }

    private function resolveClient(string $name, bool $dryRun): int
    {
        $key = strtolower($name);

        if (isset($this->clientCache[$key])) {
            return $this->clientCache[$key];
        }

        $client = Client::whereRaw('LOWER(name) = ?', [$key])->first();

        if ($client === null) {
            if (! $dryRun) {
                $client = Client::create([
                    'name' => $name,
                    'task_billability_profile' => $this->taskBillabilityProfileForImportedClient($name),
                ]);
                $this->line("  Created client: {$name}");
            } else {
                $this->clientCache[$key] = 0;

                return 0;
            }
        }

        $this->clientCache[$key] = $client->id;

        return $client->id;
    }

    private function taskBillabilityProfileForImportedClient(string $name): ClientTaskBillabilityProfile
    {
        return str_starts_with(strtolower(trim($name)), 'jdw')
            ? ClientTaskBillabilityProfile::Jdw
            : ClientTaskBillabilityProfile::Agency;
    }

    private function resolveProject(string $name, string $code, int $clientId, bool $dryRun): int
    {
        $key = strtolower($name);

        if (isset($this->projectCache[$key])) {
            return $this->projectCache[$key];
        }

        $project = Project::whereRaw('LOWER(name) = ?', [$key])
            ->where('client_id', $clientId)
            ->first();

        if ($project === null) {
            if (! $dryRun) {
                $project = Project::create([
                    'client_id' => $clientId,
                    'name' => $name,
                    'code' => $code !== '' ? $code : null,
                ]);
                $this->line("  Created project: {$name}");
            } else {
                $this->projectCache[$key] = 0;

                return 0;
            }
        }

        $this->projectCache[$key] = $project->id;

        return $project->id;
    }

    private function resolveTask(string $name, bool $dryRun): int
    {
        $key = strtolower($name);

        if (isset($this->taskCache[$key])) {
            return $this->taskCache[$key];
        }

        $task = Task::whereRaw('LOWER(name) = ?', [$key])->first();

        if ($task === null) {
            if (! $dryRun) {
                $task = Task::create(['name' => $name]);
                $this->line("  Created task: {$name}");
            } else {
                $this->taskCache[$key] = 0;

                return 0;
            }
        }

        $this->taskCache[$key] = $task->id;

        return $task->id;
    }
}
