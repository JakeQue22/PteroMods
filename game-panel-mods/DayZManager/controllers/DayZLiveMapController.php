<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZLiveBridgeService;
use GamePanelMods\DayZManager\Services\DayZLiveMapService;
use GamePanelMods\DayZManager\Services\DayZManagerSettingsService;
use GamePanelMods\DayZManager\Services\DayZMapMarkerService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZPlayerDirectoryService;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use Throwable;

/**
 * Renders and serves live player-map snapshots.
 */
final class DayZLiveMapController
{
    public function __construct(
        private readonly DayZLiveMapService $service = new DayZLiveMapService(),
        private readonly DayZLiveBridgeService $bridge = new DayZLiveBridgeService(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
        private readonly DayZMapMarkerService $mapMarkers = new DayZMapMarkerService(),
        private readonly DayZPlayerDirectoryService $directory = new DayZPlayerDirectoryService(),
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

        // Auto-deploy the bridge script the first time the live map page is
        // opened for a server (i.e. during server setup) if it is not already
        // present in the container.  Failures are intentionally silent so a
        // Wings connectivity issue never blocks the page from rendering.
        //
        // If the bridge script is missing but the activation block is already
        // in init.c, strip the block so that a subsequent server start does not
        // crash with "Can't find file 'pteromods_live_map.c'".  A full re-deploy
        // is then attempted so the server can be restarted cleanly.
        try {
            $status = $this->bridge->status($resolved['model']);

            if (!$status['script']) {
                if ($status['init_c']) {
                    // Broken state: include present but file missing.  Remove the
                    // include first so the server won't crash, then re-deploy.
                    $this->bridge->undeploy($resolved['model']);
                }
                $this->bridge->deploy($resolved['model']);
            }
        } catch (Throwable) {
            // Best-effort; never block the page render.
        }

        try {
            $snapshot = $this->service->snapshot($resolved['model']);
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'live-map', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return ['server_id' => $resolved['id']] + $snapshot;
        }

        $tileUrl = trim((string) $this->settings->get('live_map_tile_url', ''));

        return $this->renderer->render('live-map', $snapshot + [
            'tile_url' => $tileUrl,
            'map' => 'ChernarusPlus',
            'map_definition' => [
                'id' => 'chernarusplus',
                'name' => 'ChernarusPlus',
                'world_size' => 15360.0,
                'locations' => [],
            ],
        ], 'live-map', $resolved['id'], $resolved['name']);
    }

    /**
     * Returns the categorised map overlays (named locations, animal and
     * infected territories, event spawns, loot, vehicles, player spawns) and
     * the player directory used to enrich the player overlay popups.
     *
     * @return array<string, mixed>
     */
    public function markers(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);

        try {
            $snapshot = $this->service->snapshot($resolved['model']);
            $mapName = (string) ($snapshot['map'] ?? 'ChernarusPlus');
            $livePlayers = is_array($snapshot['players'] ?? null) ? $snapshot['players'] : [];
            $markers = $this->mapMarkers->markers($resolved['model'], $mapName);
            $directory = $this->directory->directory($resolved['model'], $mapName, $livePlayers);

            return [
                'server_id' => $resolved['id'],
                'status' => $markers['status'],
                'map' => $mapName,
                'groups' => $markers['groups'],
                'sources' => $markers['sources'],
                'player_directory' => $directory['players'],
                'persistence_status' => $directory['status'],
            ];
        } catch (Throwable $exception) {
            return [
                'server_id' => $resolved['id'],
                'status' => 'error',
                'message' => $exception->getMessage(),
                'groups' => [],
                'sources' => [],
                'player_directory' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);

        try {
            return ['server_id' => $resolved['id']] + $this->service->snapshot($resolved['model']);
        } catch (Throwable $exception) {
            return [
                'server_id' => $resolved['id'],
                'status'    => 'error',
                'message'   => $exception->getMessage(),
                'players'   => [],
                'online_count' => 0,
            ];
        }
    }

    /**
     * Accepts a signed transient player snapshot from a server-side bridge.
     *
     * @return array<string, mixed>
     */
    public function ingest(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);
        $this->context->authorizeManage($resolved['model']);

        $payload = $this->context->input('payload', []);

        if (!is_array($payload)) {
            $payload = [];
        }

        $signature = '';

        if (function_exists('request')) {
            try {
                $request = request();
                $signature = is_object($request) && method_exists($request, 'header')
                    ? trim((string) $request->header('X-DayZ-Bridge-Signature', ''))
                    : '';
            } catch (Throwable) {
                $signature = '';
            }
        }

        return $this->service->ingest($resolved['model'], $payload, $signature);
    }

    /**
     * Deploys the server-side bridge script to the server container.
     *
     * Called automatically on the first live map page load, and available as
     * an explicit POST endpoint so the UI can offer a "Re-deploy bridge" button.
     *
     * @return array<string, mixed>
     */
    public function setupBridge(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);
        $this->context->authorizeManage($resolved['model']);

        try {
            return $this->bridge->deploy($resolved['model']);
        } catch (Throwable $exception) {
            return [
                'deployed' => false,
                'script'   => false,
                'snapshot' => false,
                'message'  => $exception->getMessage(),
            ];
        }
    }

    /**
     * Removes the server-side bridge script and strips the activation block
     * from init.c.  Available as an explicit POST endpoint so the UI can offer
     * a "Remove bridge" button and to recover servers in a broken state
     * (init.c has the #include but pteromods_live_map.c is missing).
     *
     * @return array<string, mixed>
     */
    public function removeBridge(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);
        $this->context->authorizeManage($resolved['model']);

        try {
            return $this->bridge->undeploy($resolved['model']);
        } catch (Throwable $exception) {
            return [
                'undeployed' => false,
                'init_c'     => false,
                'script'     => false,
                'message'    => $exception->getMessage(),
            ];
        }
    }

    /**
     * Returns the current bridge deployment status for the server without
     * writing any files.
     *
     * @return array<string, mixed>
     */
    public function bridgeStatus(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);

        try {
            return $this->bridge->status($resolved['model']);
        } catch (Throwable $exception) {
            return [
                'deployed' => false,
                'script'   => false,
                'snapshot' => false,
                'message'  => $exception->getMessage(),
            ];
        }
    }
}
