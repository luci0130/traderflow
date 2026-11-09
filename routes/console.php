<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Image housekeeping: purge reviewed price-photo files and any image file no
 * record points at any more. Runs every 10 days.
 */
Schedule::command('images:prune-orphans')
    ->cron('0 3 */10 * *')
    ->withoutOverlapping();

Schedule::command('currency:refresh')
    ->dailyAt('13:10')
    ->timezone('Europe/Bucharest')
    ->withoutOverlapping();
