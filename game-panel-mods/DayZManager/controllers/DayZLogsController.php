<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
use GamePanelMods\DayZManager\Services\DayZLogsService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use Throwable;

final class DayZLogsController
{
    public function __construct(
        private readonly DayZLogsService $service = new DayZLogsService(),
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    public function index(mixed $server = null): mixed
    {
        $resolved = $this->context->resolve($server);
        $this->warmer->tick($resolved['model']);

        try {
            $clientId = $this->context->clientIdentifier($resolved['model'], $resolved['id']);
            $data = [
                'client_id' => $clientId,
                'groups' => $this->service->groups($resolved['model'], $clientId),
            ];
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'logs', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return $data;
        }

        return $this->renderer->render('logs', $data, 'logs', $resolved['id'], $resolved['name']);
    }
}
