<?php

namespace App\Models;

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
}
