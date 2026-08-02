<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Builds DayZ dashboard card data.
 */
final class DayZDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'server_name' => 'DayZ Community Server',
            'current_map' => 'ChernarusPlus',
            'server_version' => '1.25.158593',
            'installed_mods' => 3,
            'player_count' => '0 / 60',
            'cpu' => '18%',
            'ram' => '4.2 GB / 8 GB',
            'disk' => '32 GB / 80 GB',
            'server_status' => 'offline',
        ];
    }
}
