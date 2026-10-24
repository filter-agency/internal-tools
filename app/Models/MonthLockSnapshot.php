<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hours issued for one project and task when a month was locked.
 *
 * @property int $id
 * @property int $month_lock_id
 * @property int $project_id
 * @property int $task_id
 * @property float $hours
 * @property MonthLock $monthLock
 * @property Project $project
 * @property Task $task
 */
class MonthLockSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['month_lock_id', 'project_id', 'task_id', 'hours'];

    protected function casts(): array
    {
        return [
            'hours' => Decimal::class.':2',
        ];
    }

    /** @return BelongsTo<MonthLock, $this> */
    public function monthLock(): BelongsTo
    {
        return $this->belongsTo(MonthLock::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
