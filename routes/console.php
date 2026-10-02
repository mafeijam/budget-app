<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

Artisan::command('run1', function () {
    $dateStart = Carbon::parse('2021-06-08');
    $dateEnd = Carbon::parse('2021-08-06');
    dd($dateStart->diffInDays($dateEnd));
});

// Twice a day at 06:30 and 18:30 in Hong Kong: the morning run takes New York's
// close overnight with Hong Kong's the afternoon before, and the evening run picks
// up Hong Kong's close the same day. The fetch reaches a week back, so a missed
// day fills in on the next. Positions has a button to run it now.
// Only runs if something calls schedule:run every minute -- see README.
Schedule::command('prices:fetch')->twiceDailyAt(6, 18, 30)->timezone('Asia/Hong_Kong');

// Just after midnight in Hong Kong, the day today() turns over. A missed run catches up
// on the next, and saving a rule records what is due at once.
Schedule::command('recurring:record')->dailyAt('00:05')->timezone('Asia/Hong_Kong');

// A dump of the database into storage/app/backups, after the night's recurring rows are
// written. Retention is in config/backup.php; failures go to the log, not to mail.
Schedule::command('backup:clean')->dailyAt('01:00')->timezone('Asia/Hong_Kong');
Schedule::command('backup:run --only-db')->dailyAt('01:30')->timezone('Asia/Hong_Kong');
