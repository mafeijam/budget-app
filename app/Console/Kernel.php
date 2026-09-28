<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Twice a day, each after a market the app holds has closed, in Hong Kong time:
        // Hong Kong closes at 16:00 and New York at 04:00 or 05:00 the next morning.
        // Only runs if something calls schedule:run every minute -- see README.
        $schedule->command('prices:fetch')->dailyAt('17:00')->timezone('Asia/Hong_Kong');
        $schedule->command('prices:fetch')->dailyAt('06:30')->timezone('Asia/Hong_Kong');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
