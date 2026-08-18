<?php

declare(strict_types=1);

/**
 * DayZ Manager scheduler registration.
 *
 * Include this file from the Pterodactyl panel's Console Kernel
 * (`app/Console/Kernel.php`) inside the `schedule()` method:
 *
 *     protected function schedule(Schedule $schedule): void
 *     {
 *         // ... existing panel schedules ...
 *         require base_path('game-panel-mods/DayZManager/schedule.php');
 *     }
 *
 * This registers the `p:dayz:tick` command to run every minute, ensuring
 * restart schedules, mod-install follow-ups, and log scrubbing all fire
 * without requiring a browser tab to be open.
 */

use Illuminate\Console\Scheduling\Schedule;

if (!isset($schedule) || !($schedule instanceof Schedule)) {
    return;
}

$schedule->command('p:dayz:tick')->everyMinute()->withoutOverlapping(2);
