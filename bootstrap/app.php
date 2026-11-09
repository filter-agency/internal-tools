<?php

use App\Http\Middleware\AppVersionHeader;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RestrictFrameAncestors;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// PHP 8.5 deprecates PDO::MYSQL_ATTR_SSL_CA in favour of Pdo\Mysql::ATTR_SSL_CA.
// Laravel's bundled vendor/laravel/framework/config/database.php still uses the old constant
// (loaded by Foundation\Bootstrap\LoadConfiguration as a base layer beneath our own config).
// Our own config/database.php already uses the numeric 1014 workaround, so MySQL connections work fine —
// we just need to silence the noisy framework warning until upstream ships a fix.
// Remove this handler once Laravel updates its vendored config/database.php.
$previousErrorHandler = set_error_handler(function (int $level, string $message, string $file, int $line) use (&$previousErrorHandler) {
    if ($level === E_DEPRECATED
        && str_contains($message, 'PDO::MYSQL_ATTR_SSL_CA')
        && str_contains($file, 'vendor/laravel/framework/config/database.php')
    ) {
        return true;
    }

    return $previousErrorHandler ? ($previousErrorHandler)($level, $message, $file, $line) : false;
});

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Nightly DB backup to DO Spaces at 02:00, then cleanup at 02:30.
        $schedule->command('backup:run --only-db')->dailyAt('02:00');
        $schedule->command('backup:clean')->dailyAt('02:30');
        $schedule->command('backup:send-weekly-summary')->mondays()->at('09:00');

        // Restore-test on the first Saturday of each month (staging only).
        $schedule->command('backup:restore-test')
            ->weeklyOn(6, '04:00')
            ->when(fn () => now()->day <= 7);

        // Resolve Slack user IDs nightly so new joiners pick up the channel without manual intervention.
        $schedule->command('slack:sync-user-ids')->dailyAt('03:00');

        // Timesheet reminders. Times are in APP_TIMEZONE (Europe/London).
        $schedule->command('timesheets:send-reminders --type=mid-week')
            ->thursdays()->at('13:00');
        $schedule->command('timesheets:send-reminders --type=weekly-overdue')
            ->mondays()->at('09:30');
        $schedule->command('timesheets:send-reminders --type=monthly-overdue')
            ->monthlyOn(1, '09:30');
        $schedule->command('timesheets:send-reminders --type=manager-digest')
            ->mondays()->at('08:45');
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('auth.login'));
        $middleware->web(append: [EnsureUserIsActive::class, RestrictFrameAncestors::class, AppVersionHeader::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
