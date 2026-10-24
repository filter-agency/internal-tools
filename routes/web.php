<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Diagnostics\PickerDiagnosticController;
use App\Http\Controllers\Integrations\AsanaOAuthController;
use App\Http\Controllers\Integrations\AsanaTimerStatusController;
use App\Http\Controllers\Mcp\PendingActionController as McpPendingActionController;
use App\Livewire\Admin\Clients\Index as AdminClients;
use App\Livewire\Admin\Integrations\AsanaSettings as AdminAsanaSettings;
use App\Livewire\Admin\MonthLocks\Index as AdminMonthLocks;
use App\Livewire\Admin\Notifications\Index as AdminNotifications;
use App\Livewire\Admin\Projects\Edit as AdminProjectEdit;
use App\Livewire\Admin\Projects\Index as AdminProjects;
use App\Livewire\Admin\Rates\Library as AdminRatesLibrary;
use App\Livewire\Admin\Tasks\Index as AdminTasks;
use App\Livewire\Admin\Teams\Index as AdminTeams;
use App\Livewire\Admin\TimeEntries\BulkMove as AdminTimeEntriesBulkMove;
use App\Livewire\Admin\Timesheets\Index as AdminTimesheets;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Livewire\Integrations\AsanaLogTime;
use App\Livewire\Profile\ApiTokens as ProfileApiTokens;
use App\Livewire\Profile\AsanaConnection as ProfileAsanaConnection;
use App\Livewire\Profile\Preferences as ProfilePreferences;
use App\Livewire\Reports\ClientDetail;
use App\Livewire\Reports\ClientsReport;
use App\Livewire\Reports\ProjectDetail;
use App\Livewire\Reports\ProjectsReport;
use App\Livewire\Reports\ReconciliationReport;
use App\Livewire\Reports\TasksReport;
use App\Livewire\Reports\TeamOverviewReport;
use App\Livewire\Reports\TeamReport;
use App\Livewire\Reports\TimeReport;
use App\Livewire\Schedule\ScheduleBoard;
use App\Livewire\Timesheet\DayView;
use App\Livewire\Timesheet\WeekView;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Local-only demo login — bypasses Google SSO for local tours.
if (app()->environment('local')) {
    Route::get('/demo-login', function () {
        $user = User::where('email', config('app.admin_email', env('ADMIN_EMAIL')))->firstOrFail();
        auth()->login($user);

        return redirect()->route('timesheet');
    })->name('demo.login');
}

// Auth routes (unauthenticated)
Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback')->middleware('throttle:google-oauth');
Route::get('/auth/error', fn () => view('auth.error'))->name('auth.error');
Route::get('/login', fn () => view('auth.login'))->name('auth.login');
Route::post('/auth/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('auth.logout')->middleware('auth');

// Running-timer status for the browser extension's toolbar icon.
// Unauthenticated on purpose: guests get {running: false}, not a redirect.
Route::get('/asana-app/timer-status', AsanaTimerStatusController::class)
    ->name('asana-app.timer-status');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('timesheet'));

    // Client-side diagnostic sink for the "dead task picker" investigation.
    Route::post('/diagnostics/picker', [PickerDiagnosticController::class, 'store'])
        ->name('diagnostics.picker')
        ->middleware('throttle:20,1');
    // The compact log-time form rendered inside the browser extension's
    // overlay iframe on app.asana.com (also target of legacy Asana
    // attachment cards).
    Route::get('/asana-app/tasks/{taskGid}', AsanaLogTime::class)->name('asana-app.tasks.show');
    Route::get('/timesheet', DayView::class)->name('timesheet');
    Route::get('/timesheet/week', WeekView::class)->name('timesheet.week');
    Route::get('/schedule', ScheduleBoard::class)
        ->name('schedule')
        ->middleware('can:access-reports');
    Route::get('/team/{user}', DayView::class)
        ->name('team.timesheet')
        ->middleware('can:view-team-timesheet,user');
    Route::get('/team/{user}/week', WeekView::class)
        ->name('team.timesheet.week')
        ->middleware('can:view-team-timesheet,user');
    Route::get('/timesheet/song/{date}', function (string $date) {
        $path = base_path('sourcefiles/songs/depeche_mode_song_titles.csv');
        $handle = fopen($path, 'r');
        $songs = [];
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (($row[3] ?? '') === 'album_track') {
                $songs[] = ['song_name' => $row[0], 'album' => $row[1], 'year' => $row[2]];
            }
        }
        fclose($handle);
        // Seed by date so same day always returns same song
        mt_srand((int) crc32($date));
        $song = $songs[mt_rand(0, count($songs) - 1)];

        return response()->json($song);
    })->name('timesheet.song');

    // Report routes (manager + admin)
    Route::middleware('can:access-reports')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/time', TimeReport::class)->name('time');
        Route::get('/clients', ClientsReport::class)->name('clients');
        Route::get('/clients/{client}', ClientDetail::class)->name('client-detail');
        Route::get('/projects', ProjectsReport::class)->name('projects');
        Route::get('/projects/{project}', ProjectDetail::class)->name('projects.detail');
        Route::get('/projects/{project}/budget', fn (string $project) => redirect()->route('reports.projects.detail', $project))
            ->name('projects.budget');
        Route::get('/tasks', TasksReport::class)->name('tasks');
        Route::get('/reconciliation', ReconciliationReport::class)->name('reconciliation');
        Route::get('/team', TeamOverviewReport::class)->name('team');
        Route::get('/team/{user}', TeamReport::class)->name('team.member');
    });

    // Admin routes
    Route::middleware('can:manage-projects')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/projects', AdminProjects::class)->name('projects');
        Route::get('/projects/{project}/edit', AdminProjectEdit::class)->name('projects.edit');
    });

    Route::middleware('can:access-admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', AdminUsers::class)->name('users');
        Route::get('/clients', AdminClients::class)->name('clients');
        Route::get('/tasks', AdminTasks::class)->name('tasks');
        Route::get('/teams', AdminTeams::class)->name('teams');
        Route::redirect('/rates', '/admin/rates/library')->name('rates');
        Route::get('/rates/library', AdminRatesLibrary::class)->name('rates.library');
        Route::get('/timesheets', AdminTimesheets::class)->name('timesheets');
        Route::get('/time-entries/bulk-move', AdminTimeEntriesBulkMove::class)->name('time-entries.bulk-move');
        Route::get('/month-locks', AdminMonthLocks::class)->name('month-locks');
        Route::get('/timesheets/{user}', DayView::class)->name('timesheets.user');
        Route::get('/timesheets/{user}/week', WeekView::class)->name('timesheets.user.week');
        Route::get('/integrations/asana', AdminAsanaSettings::class)->name('integrations.asana');
        Route::get('/notifications', AdminNotifications::class)->name('notifications');
    });

    // Profile / personal integrations
    Route::get('/profile/asana', ProfileAsanaConnection::class)->name('profile.asana');
    Route::get('/profile/api-tokens', ProfileApiTokens::class)->name('profile.api-tokens');
    Route::get('/profile/preferences', ProfilePreferences::class)->name('profile.preferences');
    Route::view('/profile/mcp', 'profile.mcp')->name('profile.mcp');

    // MCP high-impact write approvals. These use the web session and CSRF
    // protection; OAuth clients can request approvals but cannot approve them.
    Route::prefix('mcp/pending-actions')->name('mcp.pending-actions.')->group(function () {
        Route::get('/{pendingAction:approval_token}', [McpPendingActionController::class, 'show'])->name('show');
        Route::post('/{pendingAction:approval_token}/approve', [McpPendingActionController::class, 'approve'])->name('approve');
        Route::post('/{pendingAction:approval_token}/reject', [McpPendingActionController::class, 'reject'])->name('reject');
    });

    // Asana OAuth
    Route::prefix('integrations/asana')->name('integrations.asana.')->group(function () {
        Route::get('/redirect', [AsanaOAuthController::class, 'redirect'])
            ->name('redirect')
            ->middleware('throttle:asana-oauth');
        Route::get('/callback', [AsanaOAuthController::class, 'callback'])
            ->name('callback')
            ->middleware('throttle:asana-oauth');
        Route::post('/disconnect', [AsanaOAuthController::class, 'disconnect'])->name('disconnect');
    });
});
