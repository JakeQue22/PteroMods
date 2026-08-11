<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
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

            $players = $this->service->allLists();
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'players', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return ['server_id' => $resolved['id'], 'players' => $players];
        }

        return $this->renderer->render('players', ['players' => $players], 'players', $resolved['id'], $resolved['name']);
    }

    /**
     * @return array<string, mixed>
     */
    public function add(mixed $server = null, string $listType = '', string $playerId = '', string $note = '', string $addedBy = ''): array
    {
        $playerId = $playerId !== '' ? $playerId : $this->context->stringInput('player_id');
        $note = $note !== '' ? $note : $this->context->stringInput('note');
        $addedBy = $addedBy !== '' ? $addedBy : $this->context->stringInput('added_by');

        return $this->service->add($listType, $playerId, $note, $addedBy);
    }

    /**
     * @return array<string, string>
     */
    public function remove(mixed $server = null, string $listType = '', string $playerId = ''): array
    {
        $playerId = $playerId !== '' ? $playerId : $this->context->stringInput('player_id');

        return $this->service->remove($listType, $playerId);
    }
}
