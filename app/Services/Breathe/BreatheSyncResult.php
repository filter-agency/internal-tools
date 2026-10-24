<?php

namespace App\Services\Breathe;

final readonly class BreatheSyncResult
{
    /**
     * @param  list<array{name: string, changes: array<string, array{from: string|null, to: string|null}>}>  $updated
     * @param  list<string>  $unmatched  Current Breathe employees with no Internal Tools account.
     * @param  list<string>  $problems  Records the sync skipped or could not reconcile.
     */
    public function __construct(
        public array $updated,
        public int $unchanged,
        public array $unmatched,
        public array $problems,
    ) {}

    public function needsAttention(): bool
    {
        return $this->unmatched !== [] || $this->problems !== [];
    }
}
