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

        if (function_exists('view')) {
            $viewFile = __DIR__ . '/../views/dashboard.blade.php';
            $view = view()->file($viewFile, $dashboard);
            return function_exists('response') ? response($view->render(), 200, ['Content-Type' => 'text/html; charset=utf-8']) : $view;
        }

        return $dashboard;
    }
}
