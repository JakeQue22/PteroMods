<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
use GamePanelMods\DayZManager\Services\DayZLiveMapService;
use GamePanelMods\DayZManager\Services\DayZObservedPlayerService;
use GamePanelMods\DayZManager\Services\DayZPlayerDirectoryService;
use GamePanelMods\DayZManager\Services\DayZPlayerService;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use GamePanelMods\DayZManager\Services\DayZVppAdminService;
use Throwable;

/**
 * Manages DayZ ban, whitelist, and priority player lists.
 */
final class DayZPlayerController
{
    public function __construct(
        private readonly DayZPlayerService $service = new DayZPlayerService(),
        private readonly DayZObservedPlayerService $observedPlayers = new DayZObservedPlayerService(),
        private readonly DayZLiveMapService $liveMap = new DayZLiveMapService(),
        private readonly DayZPlayerDirectoryService $directory = new DayZPlayerDirectoryService(),
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZVppAdminService $vppAdmin = new DayZVppAdminService(),
    ) {
    }

    /**
     * Renders the player list page, or returns a single list for API requests.
     *
     * @return mixed
     */
    public function index(mixed $server = null, string $listType = '')
    {
        $resolved = $this->context->resolve($server);
        $this->warmer->tick($resolved['model']);

        try {
            if ($listType !== '') {
                return [
                    'list_type' => $listType,
                    'entries'   => $this->service->list($listType),
                ];
            }

            $snapshot = $this->liveMap->snapshot($resolved['model']);
            $livePlayers = is_array($snapshot['players'] ?? null) ? $snapshot['players'] : [];
            $mapName = (string) ($snapshot['map'] ?? 'ChernarusPlus');
            $persisted = $this->directory->directory($resolved['model'], $mapName, $livePlayers);
            $playerLists = $this->service->allLists();
            $superadminIds = $this->vppAdmin->list($resolved['model']);
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'players', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return [
                'server_id' => $resolved['id'],
                'players' => $persisted['players'] ?? [],
                'live_players' => $livePlayers,
                'player_lists' => $playerLists,
                'persistence_status' => $persisted['status'] ?? 'not_found',
                'persistence_source_path' => $persisted['source_path'] ?? null,
                'persistence_source_paths' => $persisted['source_paths'] ?? [],
                'map_definition' => $snapshot['map_definition'] ?? null,
                'superadmin_ids' => $superadminIds,
            ];
        }

        return $this->renderer->render('players', [
            'player_lists' => $playerLists,
            'persisted_players' => $persisted['players'] ?? [],
            'live_players' => $livePlayers,
            'persistence_status' => $persisted['status'] ?? 'not_found',
            'persistence_source_path' => $persisted['source_path'] ?? null,
            'persistence_source_paths' => $persisted['source_paths'] ?? [],
            'map_definition' => $snapshot['map_definition'] ?? ['name' => 'ChernarusPlus', 'locations' => []],
            'online_count' => count($livePlayers),
            'superadmin_ids' => $superadminIds,
            'protected_steam64' => DayZVppAdminService::PROTECTED_STEAM64,
        ], 'players', $resolved['id'], $resolved['name']);
    }

    /**
     * @return array<string, mixed>
     */
    public function add(mixed $server = null, string $listType = '', string $playerId = '', string $note = '', string $addedBy = ''): array
    {
        $model = $this->authoriseManage($server);
        $playerId = $playerId !== '' ? $playerId : $this->context->stringInput('player_id');
        $note = $note !== '' ? $note : $this->context->stringInput('note');
        $addedBy = $addedBy !== '' ? $addedBy : $this->actorName($this->context->stringInput('added_by'));
        $nickname = $this->context->stringInput('nickname');

        return $this->service->add($listType, $playerId, $note, $addedBy, $nickname, $model);
    }

    /**
     * @return array<string, string>
     */
    public function remove(mixed $server = null, string $listType = '', string $playerId = ''): array
    {
        $model = $this->authoriseManage($server);
        $playerId = $playerId !== '' ? $playerId : $this->context->stringInput('player_id');

        return $this->service->remove($listType, $playerId, $model);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetObserved(mixed $server = null, string $id = ''): array
    {
        try {
            $model = $this->authoriseManage($server);
            $playerId = $id !== '' ? $id : $this->context->stringInput('player_id');

            return $this->observedPlayers->reset($model, $playerId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreObserved(mixed $server = null, string $id = ''): array
    {
        try {
            $model = $this->authoriseManage($server);
            $playerId = $id !== '' ? $id : $this->context->stringInput('player_id');
            $backupId = $this->context->stringInput('backup_id');

            return $this->observedPlayers->restore($model, $playerId, $backupId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function kick(mixed $server = null): array
    {
        try {
            $model = $this->authoriseManage($server);
            $playerId = $this->context->stringInput('player_id');

            return $this->observedPlayers->kick($model, $playerId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Adds a player as a VPP SuperAdmin.
     *
     * @return array<string, mixed>
     */
    public function addSuperadmin(mixed $server = null): array
    {
        try {
            $model = $this->authoriseManage($server);
            $steam64  = $this->context->stringInput('steam64');
            $nickname = $this->context->stringInput('nickname');

            return $this->vppAdmin->add($model, $steam64, $nickname);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Removes a player from the VPP SuperAdmins list.
     *
     * @return array<string, mixed>
     */
    public function removeSuperadmin(mixed $server = null, string $steam64 = ''): array
    {
        try {
            $model   = $this->authoriseManage($server);
            $steam64 = $steam64 !== '' ? $steam64 : $this->context->stringInput('steam64');

            return $this->vppAdmin->remove($model, $steam64);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    private function authoriseManage(mixed $server): mixed
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $model;
    }

    private function actorName(string $fallback = ''): string
    {
        if ($fallback !== '') {
            return $fallback;
        }

        if (!class_exists('Illuminate\\Support\\Facades\\Auth')) {
            return '';
        }

        try {
            $user = \Illuminate\Support\Facades\Auth::user();

            if ($user === null) {
                return '';
            }

            foreach (['username', 'name', 'email'] as $field) {
                $value = trim((string) ($user->{$field} ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }
        } catch (Throwable) {
            return '';
        }

        return '';
    }
}
