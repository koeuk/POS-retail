<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Prune the audit trail. `clean_after_days` in config/activitylog.php sets
 * the horizon (a year); this only decides how often the sweep runs. The log
 * has no delete route by design, so ageing rows out here is the one way they
 * ever leave — without it the table grows for the life of the shop.
 */
Schedule::command('activitylog:clean')->weekly();
