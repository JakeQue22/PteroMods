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
     * @return array<string, mixed>
     */
    public function show(): array
    {
        return $this->service->dashboard();
    }
}
