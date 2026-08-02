<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZDashboardService;

/**
 * Produces the DayZ dashboard payload for supported servers.
 */
final class DayZDashboardController
{
    public function __construct(private readonly DayZDashboardService $service = new DayZDashboardService())
    {
    }

    /**
     * @return mixed
     */
    public function show(mixed $server = null)
    {
        $dashboard = $this->service->dashboard($server);

        if (function_exists('response') && function_exists('view')) {
            return response()->view('game-panel-mods.DayZManager.views.dashboard', $dashboard);
        }

        return $dashboard;
    }
}
