<?php

namespace App\Domain\Reporting;

use App\Enums\ContractPosition;
use App\Models\UserContractPosition;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Answers "what contract position did this person hold on this date?" from the
 * dated history in user_contract_positions. The whole table is read once, so
 * build one timeline per report or export and reuse it.
 */
final class ContractPositionTimeline
{
    /**
     * @param  array<int, array<string, ContractPosition|null>>  $changesByUser  per user, effective_from (Y-m-d) => position, sorted by date
     */
    private function __construct(private readonly array $changesByUser) {}

    public static function load(): self
    {
        $changesByUser = [];

        foreach (UserContractPosition::query()->get() as $row) {
            $changesByUser[$row->user_id][$row->effective_from->toDateString()] = $row->position;
        }

        return new self(array_map(function (array $changes): array {
            ksort($changes);

            return $changes;
        }, $changesByUser));
    }

    /**
     * Null when the person held no position on that date: no change had taken
     * effect yet, or the latest change moved them outside the contracted team.
     */
    public function on(int $userId, CarbonInterface|string $date): ?ContractPosition
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $position = null;
        foreach ($this->changesByUser[$userId] ?? [] as $effectiveFrom => $changedTo) {
            if ($effectiveFrom > $day) {
                break;
            }

            $position = $changedTo;
        }

        return $position;
    }

    public function labelOn(int $userId, CarbonInterface|string $date): string
    {
        return $this->on($userId, $date)?->label() ?? ContractPosition::OUTSIDE_LABEL;
    }
}
