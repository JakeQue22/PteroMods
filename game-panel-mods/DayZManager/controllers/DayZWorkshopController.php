<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZWorkshopService;

/**
 * Produces Workshop management payloads for DayZ mods.
 */
final class DayZWorkshopController
{
    public function __construct(private readonly DayZWorkshopService $service = new DayZWorkshopService())
    {
    }

    /**
     * @return mixed
     */
    public function index(mixed $server = null)
    {
        $serverId = '';
        if (is_string($server) && $server !== '') {
            $serverId = $server;
        } elseif (function_exists('request')) {
            $routeServer = request()->route('server');
            if (is_string($routeServer)) {
                $serverId = $routeServer;
            }
        }

        $data = [
            'server_id'     => $serverId,
            'settings'      => $this->service->settings(),
            'installed_mods' => $this->service->installedMods(),
        ];

        if (function_exists('view')) {
            $viewFile = __DIR__ . '/../views/mods.blade.php';
            $view = view()->file($viewFile, $data);
            return function_exists('response') ? response($view->render(), 200, ['Content-Type' => 'text/html; charset=utf-8']) : $view;
        }

        return $data;
    }

    /**
     * @param array<string, array{dependencies?: list<string>, requires_cf?: bool}> $metadata
     * @return array<string, mixed>
     */
    public function install(string $reference, array $metadata = []): array
    {
        return $this->service->installPlan($reference, $metadata);
    }

    /**
     * @return array<string, string>
     */
    public function update(string $workshopId): array
    {
        return $this->service->update($workshopId);
    }

    /**
     * @return array<string, string>
     */
    public function remove(string $workshopId): array
    {
        return $this->service->remove($workshopId);
    }

    /**
     * @return array<string, string>
     */
    public function enable(string $workshopId): array
    {
        return $this->service->toggle($workshopId, true);
    }

    /**
     * @return array<string, string>
     */
    public function disable(string $workshopId): array
    {
        return $this->service->toggle($workshopId, false);
    }

    /**
     * @param list<string> $orderedWorkshopIds  Workshop IDs in the desired load order.
     * @return array<string, mixed>
     */
    public function reorder(array $orderedWorkshopIds): array
    {
        return $this->service->reorder($orderedWorkshopIds);
    }
}
