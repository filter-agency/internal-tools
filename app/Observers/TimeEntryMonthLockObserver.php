<?php

namespace App\Observers;

use App\Domain\TimeTracking\MonthLockGuard;
use App\Models\TimeEntry;

/**
 * Enforces month locks on every Eloquent write to a time entry. Query-builder
 * mass updates and withoutEvents() bypass it.
 */
final class TimeEntryMonthLockObserver
{
    public function __construct(private readonly MonthLockGuard $guard) {}

    public function creating(TimeEntry $entry): void
    {
        $this->guard->assertWritable($entry, 'created');
    }

    public function updating(TimeEntry $entry): void
    {
        $this->guard->assertWritable($entry, 'updated');
    }

    public function deleting(TimeEntry $entry): void
    {
        $this->guard->assertWritable($entry, 'deleted');
    }

    public function created(TimeEntry $entry): void
    {
        $this->guard->record($entry, 'created');
    }

    public function updated(TimeEntry $entry): void
    {
        $this->guard->record($entry, 'updated');
    }

    public function deleted(TimeEntry $entry): void
    {
        $this->guard->record($entry, 'deleted');
    }
}
