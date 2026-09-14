<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly rollup of yesterday's usage_events into usage_daily_aggregates.
Schedule::command('usage:aggregate-daily')->dailyAt('01:00');

// Runs after aggregation so the day it closes out is already rolled up.
Schedule::command('billing:generate-due-invoices')->dailyAt('02:00');
