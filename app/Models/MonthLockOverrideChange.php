<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One time-entry change made under an override. time_entry_id has no foreign
 * key so the log survives the entry being deleted.
 *
 * @property int $id
 * @property int $month_lock_override_id
 * @property int $time_entry_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property Carbon $created_at
 * @property MonthLockOverride $override
 */
class MonthLockOverrideChange extends Model
{
    public const UPDATED_AT = null;

    /** Logged field => label, in display order. */
    private const FIELD_LABELS = [
        'user_id' => 'User',
        'project_id' => 'Project',
        'task_id' => 'Task',
        'spent_on' => 'Date',
        'hours' => 'Hours',
        'notes' => 'Notes',
        'is_billable' => 'Billable',
        'billable_rate_snapshot' => 'Rate',
        'billable_amount' => 'Amount',
        'is_running' => 'Timer',
    ];

    protected $fillable = ['month_lock_override_id', 'time_entry_id', 'action', 'before', 'after'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MonthLockOverride, $this> */
    public function override(): BelongsTo
    {
        return $this->belongsTo(MonthLockOverride::class, 'month_lock_override_id');
    }

    /**
     * Labels of the logged fields an update changed, so a re-allocation that
     * keeps the hours is not mistaken for a no-op. Empty for created and
     * deleted entries, which have only one side.
     *
     * @return array<int, string>
     */
    public function changedFieldLabels(): array
    {
        if ($this->before === null || $this->after === null) {
            return [];
        }

        $labels = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            if (self::valuesDiffer($field, $this->before[$field] ?? null, $this->after[$field] ?? null)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * JSON turns 2.0 into 2, and SQLite keeps a time on dates, so numbers
     * compare as numbers, dates by day, and missing notes as empty.
     */
    private static function valuesDiffer(string $field, mixed $before, mixed $after): bool
    {
        if ($before === null || $after === null) {
            return $field === 'notes' ? (string) $before !== (string) $after : $before !== $after;
        }

        return match ($field) {
            'hours', 'billable_rate_snapshot', 'billable_amount' => abs((float) $before - (float) $after) >= 0.005,
            'spent_on' => CarbonImmutable::parse($before)->toDateString() !== CarbonImmutable::parse($after)->toDateString(),
            default => $before !== $after,
        };
    }
}
