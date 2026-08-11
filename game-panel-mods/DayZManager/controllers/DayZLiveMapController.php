<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZLiveMapService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use Throwable;

/**
 * Renders and serves live player-map snapshots.
 */
final class DayZLiveMapController
{
    public function __construct(
        private readonly DayZLiveMapService $service = new DayZLiveMapService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * @return mixed
     */
    public function index(mixed $server = null)
    {
        $resolved = $this->context->resolve($server);

        try {
            $snapshot = $this->service->snapshot($resolved['model']);
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'live-map', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return ['server_id' => $resolved['id']] + $snapshot;
        }

        return $this->renderer->render('live-map', $snapshot, 'live-map', $resolved['id'], $resolved['name']);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);

        return ['server_id' => $resolved['id']] + $this->service->snapshot($resolved['model']);
    }
}
