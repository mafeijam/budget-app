<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

Artisan::command('run1', function () {
    $dateStart = Carbon::parse('2021-06-08');
    $dateEnd = Carbon::parse('2021-08-06');
    dd($dateStart->diffInDays($dateEnd));
});

// Once a day, at 06:30 in Hong Kong: after New York's close overnight and Hong Kong's
// the afternoon before, so one run takes both markets' last close. The fetch reaches a
// week back, so a missed day fills in on the next. Positions has a button to run it now.
// Only runs if something calls schedule:run every minute -- see README.
Schedule::command('prices:fetch')->dailyAt('06:30')->timezone('Asia/Hong_Kong');
