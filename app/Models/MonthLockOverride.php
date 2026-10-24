<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $month_lock_id
 * @property int $user_id
 * @property string $reason
 * @property Carbon $expires_at
 * @property Carbon|null $closed_at
 * @property MonthLock $monthLock
 * @property User $user
 * @property Collection<int, MonthLockOverrideChange> $changes
 */
class MonthLockOverride extends Model
{
    protected $fillable = ['month_lock_id', 'user_id', 'reason', 'expires_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->closed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<MonthLockOverride>  $query
     * @return Builder<MonthLockOverride>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('closed_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<MonthLock, $this> */
    public function monthLock(): BelongsTo
    {
        return $this->belongsTo(MonthLock::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MonthLockOverrideChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(MonthLockOverrideChange::class);
    }
}
