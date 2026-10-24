<?php

namespace App\Domain\Reporting;

/**
 * One client, project and task in an issued month: the hours snapshotted when
 * the month was locked against the hours booked now.
 */
final readonly class ReconciliationRow
{
    public function __construct(
        public string $clientName,
        public string $projectName,
        public string $taskName,
        public float $issuedHours,
        public float $currentHours,
        public float $difference,
        public int $clientId,
        public int $projectId,
    ) {}

    /** Hours are kept to two decimals, so any difference at all is at least 0.01. */
    public static function isMismatch(float $difference): bool
    {
        return abs($difference) >= 0.01;
    }
}
