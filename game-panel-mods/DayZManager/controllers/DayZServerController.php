<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use GamePanelMods\DayZManager\Services\DayZServerQueryService;
use GamePanelMods\DayZManager\Services\DayZServerService;
use GamePanelMods\DayZManager\Services\DayZWorkshopService;
use Throwable;

/**
 * Handles server restart and launch parameter preview.
 */
final class DayZServerController
{
    public function __construct(
        private readonly DayZServerService $service = new DayZServerService(),
        private readonly DayZWorkshopService $workshop = new DayZWorkshopService(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function restart(mixed $server = null, string $reason = ''): array
    {
        $reason = $reason !== '' ? $reason : $this->context->stringInput('reason');

        return $this->service->restart($reason, $this->context->resolve($server)['model']);
    }

    /**
     * Sends an arbitrary power signal (`start`, `stop`, `restart`, `kill`).
     *
     * @return array<string, mixed>
     */
    public function power(mixed $server = null, string $signal = ''): array
    {
        $signal = $signal !== '' ? $signal : $this->context->stringInput('signal');

        return $this->service->power($this->context->resolve($server)['model'], $signal);
    }

    /**
     * Renders the server control page, or returns launch parameters for API requests.
     *
     * @param list<string> $enabledFolders
     * @return mixed
     */
    public function launchParameters(mixed $server = null, array $enabledFolders = [])
    {
        $resolved = $this->context->resolve($server);

        try {
            $folders = $enabledFolders !== [] ? $enabledFolders : $this->workshop->enabledFolders($resolved['model']);
            $payload = $this->service->launchParameters($resolved['model'], $folders);
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'server', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return $payload;
        }

        $payload['live'] = $this->query->query($resolved['model']);

        return $this->renderer->render('server', $payload, 'server', $resolved['id'], $resolved['name']);
    }
}
