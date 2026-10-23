<?php

namespace App\Observers;

use App\Domain\TimeTracking\MonthLockGuard;
use App\Models\Project;

final class ProjectMonthLockObserver
{
    public function __construct(private readonly MonthLockGuard $guard) {}

    public function updating(Project $project): void
    {
        $this->guard->assertProjectClientChangeAllowed($project);
    }
}
