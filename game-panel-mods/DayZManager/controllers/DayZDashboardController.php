<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZDashboardService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use Throwable;

/**
 * Produces the DayZ dashboard payload for supported servers.
 */
final class DayZDashboardController
{
    public function __construct(
        private readonly DayZDashboardService $service = new DayZDashboardService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * @return mixed
     */
    public function show(mixed $server = null)
    {
        $resolved = $this->context->resolve($server);

        try {
            $dashboard = $this->service->dashboard($resolved['model'] ?? $resolved['id']);
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'dashboard', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return $dashboard;
        }

        return $this->renderer->render(
            'dashboard',
            $dashboard,
            'dashboard',
            $resolved['id'],
            $dashboard['server_name'] ?? $resolved['name'],
        );
    }
}
