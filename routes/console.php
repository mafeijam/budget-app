<?php

use Illuminate\Support\Carbon;

Artisan::command('run1', function () {
    $dateStart = Carbon::parse('2021-06-08');
    $dateEnd = Carbon::parse('2021-08-06');
    dd($dateStart->diffInDays($dateEnd));
});
