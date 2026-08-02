<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZPlayerService;

/**
 * Manages DayZ ban, whitelist, and priority player lists.
 */
final class DayZPlayerController
{
    public function __construct(private readonly DayZPlayerService $service = new DayZPlayerService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(string $listType): array
    {
        return [
            'list_type' => $listType,
            'entries'   => $this->service->list($listType),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function add(string $listType, string $playerId, string $note = '', string $addedBy = ''): array
    {
        return $this->service->add($listType, $playerId, $note, $addedBy);
    }

    /**
     * @return array<string, string>
     */
    public function remove(string $listType, string $playerId): array
    {
        return $this->service->remove($listType, $playerId);
    }
}
