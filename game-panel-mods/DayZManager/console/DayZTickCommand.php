<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Console;

use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
use Illuminate\Console\Command;

/**
 * Artisan command that ticks all DayZ Manager background jobs (restart
 * schedules, mod-install follow-ups, log scrubbing, cache warming) for
 * every registered DayZ server.
 *
 * Register in the panel's scheduler so ticks fire independently of any
 * browser having the DayZ Manager page open:
 *
 *     $schedule->command('p:dayz:tick')->everyMinute();
 *
 * The command is also safe to call manually:
 *
 *     php artisan p:dayz:tick
 */
final class DayZTickCommand extends Command
{
    protected $signature = 'p:dayz:tick';
    protected $description = 'Tick DayZ Manager background jobs (restart schedules, log scrub, etc.)';

    public function handle(): int
    {
        $warmer = new DayZCacheWarmService();
        $warmer->tick(null);

        $this->info('DayZ Manager tick completed.');

        return self::SUCCESS;
    }
}
