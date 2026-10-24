<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property Carbon $month First day of the locked month.
 * @property Carbon $issued_on
 * @property int $locked_by_user_id
 * @property Client $client
 * @property User $lockedBy
 * @property Collection<int, MonthLockSnapshot> $snapshots
 * @property Collection<int, MonthLockOverride> $overrides
 */
class MonthLock extends Model
{
    protected $fillable = ['client_id', 'month', 'issued_on', 'locked_by_user_id'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'issued_on' => 'date',
        ];
    }

    public function label(): string
    {
        return $this->month->format('F Y');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    /** @return HasMany<MonthLockSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(MonthLockSnapshot::class);
    }

    /** @return HasMany<MonthLockOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(MonthLockOverride::class);
    }
}
