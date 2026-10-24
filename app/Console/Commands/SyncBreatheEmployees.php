<?php

namespace App\Console\Commands;

use App\Services\Breathe\BreatheClient;
use App\Services\Breathe\BreatheEmployeeSync;
use App\Services\Breathe\BreatheSyncAlert;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Throwable;

class SyncBreatheEmployees extends Command
{
    protected $signature = 'breathe:sync-employees {--dry-run : Show what would change without writing or emailing}';

    protected $description = 'Copy start dates, leave dates and employment type from Breathe HR onto matching users.';

    public function handle(BreatheClient $client, BreatheEmployeeSync $sync, BreatheSyncAlert $alert): int
    {
        if (! $client->isConfigured()) {
            $this->error('Breathe HR is not configured. Set BREATHE_API_KEY and BREATHE_BASE_URL.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $sync->run($dryRun);
        } catch (Throwable $e) {
            // A failed response's body is not echoed, as Breathe records carry personal data.
            $failure = $e instanceof RequestException
                ? "Breathe HR answered HTTP {$e->response->status()}."
                : $e->getMessage();

            $this->error("Breathe sync failed: {$failure}");
            if (! $dryRun) {
                $alert->report($failure, [], []);
            }
            report($e);

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run: nothing was written.');
        }

        if ($result->updated !== []) {
            $this->table(['Person', 'Changes'], array_map(fn (array $row): array => [
                $row['name'],
                collect($row['changes'])
                    ->map(fn (array $change, string $field): string => "{$field}: ".($change['from'] ?? '-').' -> '.($change['to'] ?? '-'))
                    ->implode("\n"),
            ], $result->updated));
        }

        $this->info(($dryRun ? 'Would update' : 'Updated').' '.count($result->updated).', unchanged '.$result->unchanged.'.');

        foreach ($result->unmatched as $line) {
            $this->warn("No Internal Tools user: {$line}");
        }
        foreach ($result->problems as $line) {
            $this->warn($line);
        }

        if (! $dryRun) {
            $result->needsAttention()
                ? $alert->report(null, $result->unmatched, $result->problems)
                : $alert->resolve();
        }

        return self::SUCCESS;
    }
}
