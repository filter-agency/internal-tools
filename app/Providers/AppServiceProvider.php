<?php

namespace App\Providers;

use App\Domain\TimeTracking\MonthLockGuard;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\Channels\SlackChannel;
use App\Observers\ProjectMonthLockObserver;
use App\Observers\TimeEntryAsanaObserver;
use App\Observers\TimeEntryMonthLockObserver;
use App\Observers\TimeEntryScheduleActualsObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped so its project-to-client memo resets per request or queued job.
        $this->app->scoped(MonthLockGuard::class);
    }

    public function boot(): void
    {
        Passport::tokensCan([
            'mcp:use' => 'Use Internal Tools MCP server',
        ]);

        Passport::authorizationView('oauth.authorize');

        RateLimiter::for('google-oauth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('asana-oauth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('queued-mail-notifications', function () {
            return Limit::perSecond(max(1, (int) config('mail.queued_notifications.rate_limit_per_second', 9)))
                ->by((string) config('mail.queued_notifications.rate_limit_key', 'resend'));
        });

        Gate::define('access-admin', fn (User $user) => $user->isAdmin());
        Gate::define('manage-projects', fn (User $user) => $user->isManager());
        Gate::define('access-reports', fn (User $user) => $user->isManager());

        // Allowed to look (read-only) at another user's timesheet:
        //   - admins (can also impersonate via the admin route which writes)
        //   - the target's direct line manager
        Gate::define('view-team-timesheet', fn (User $user, User $target) => $user->isAdmin() || $target->reports_to_user_id === $user->id);

        // Registered first so lock checks and override logging run before the
        // Asana and schedule side effects.
        TimeEntry::observe(TimeEntryMonthLockObserver::class);
        TimeEntry::observe(TimeEntryAsanaObserver::class);
        TimeEntry::observe(TimeEntryScheduleActualsObserver::class);
        Project::observe(ProjectMonthLockObserver::class);

        Notification::extend('slack', fn ($app) => $app->make(SlackChannel::class));
    }
}
