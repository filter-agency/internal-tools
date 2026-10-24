<?php

namespace App\Models;

use App\Enums\ContractPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change in the contract position a person holds. The position applies
 * from effective_from until the next row; a null position means outside the
 * contracted team.
 *
 * @property int $id
 * @property int $user_id
 * @property ContractPosition|null $position
 * @property Carbon $effective_from
 * @property User $user
 */
class UserContractPosition extends Model
{
    protected $fillable = ['user_id', 'position', 'effective_from'];

    protected function casts(): array
    {
        return [
            'position' => ContractPosition::class,
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
