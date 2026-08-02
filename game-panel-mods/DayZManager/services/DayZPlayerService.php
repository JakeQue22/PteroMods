<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Manages DayZ ban, whitelist, and priority player lists.
 */
final class DayZPlayerService
{
    private const VALID_LIST_TYPES = ['ban', 'whitelist', 'priority'];

    /**
     * @return list<array{player_id: string, note: string, added_by: string, created_at: string|null}>
     */
    public function list(string $listType): array
    {
        $this->assertValidListType($listType);

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function add(string $listType, string $playerId, string $note = '', string $addedBy = ''): array
    {
        $this->assertValidListType($listType);
        $this->assertValidPlayerId($playerId);

        return [
            'status'    => 'queued',
            'action'    => 'add',
            'list_type' => $listType,
            'player_id' => $playerId,
            'note'      => $note,
            'added_by'  => $addedBy,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function remove(string $listType, string $playerId): array
    {
        $this->assertValidListType($listType);
        $this->assertValidPlayerId($playerId);

        return [
            'status'    => 'queued',
            'action'    => 'remove',
            'list_type' => $listType,
            'player_id' => $playerId,
        ];
    }

    private function assertValidListType(string $listType): void
    {
        if (!in_array($listType, self::VALID_LIST_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid list type "%s". Must be one of: %s.', $listType, implode(', ', self::VALID_LIST_TYPES)),
            );
        }
    }

    private function assertValidPlayerId(string $playerId): void
    {
        if (trim($playerId) === '') {
            throw new \InvalidArgumentException('Player ID must not be empty.');
        }
    }
}
