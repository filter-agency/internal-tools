<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('asana:refresh-projects')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('asana:refresh-tasks')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('asana:prune-logs')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('breathe:sync-employees')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:check-budget-alerts')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('asana:check-connections')
    ->dailyAt('09:00')
    ->weekdays()
    ->withoutOverlapping()
    ->onOneServer();
