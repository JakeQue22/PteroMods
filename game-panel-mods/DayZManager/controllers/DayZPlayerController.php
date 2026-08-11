<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
use GamePanelMods\DayZManager\Services\DayZLiveMapService;
use GamePanelMods\DayZManager\Services\DayZObservedPlayerService;
use GamePanelMods\DayZManager\Services\DayZPersistencePlayerService;
use GamePanelMods\DayZManager\Services\DayZPlayerService;
use GamePanelMods\DayZManager\Services\DayZServerContext;
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
        private readonly DayZPersistencePlayerService $persistencePlayers = new DayZPersistencePlayerService(),
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
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
            $persisted = $this->persistencePlayers->snapshot($resolved['model'], (string) ($snapshot['map'] ?? 'ChernarusPlus'));
            $playerLists = $this->service->allLists();
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
                'map_definition' => $snapshot['map_definition'] ?? null,
            ];
        }

        return $this->renderer->render('players', [
            'player_lists' => $playerLists,
            'persisted_players' => $persisted['players'] ?? [],
            'live_players' => $livePlayers,
            'persistence_status' => $persisted['status'] ?? 'not_found',
            'persistence_source_path' => $persisted['source_path'] ?? null,
            'map_definition' => $snapshot['map_definition'] ?? ['name' => 'ChernarusPlus', 'locations' => []],
            'online_count' => count($livePlayers),
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

        return $this->service->add($listType, $playerId, $note, $addedBy, $model);
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
