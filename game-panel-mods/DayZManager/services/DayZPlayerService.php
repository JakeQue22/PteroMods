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

        try {
            if (class_exists('Illuminate\\Support\\Facades\\Schema')
                && class_exists('Illuminate\\Support\\Facades\\DB')
                && \Illuminate\Support\Facades\Schema::hasTable('dayz_player_lists')) {
                $rows = \Illuminate\Support\Facades\DB::table('dayz_player_lists')
                    ->where('list_type', $listType)
                    ->orderBy('player_id')
                    ->get()
                    ->toArray();

                return array_map(static function ($row): array {
                    $row = (array) $row;

                    return [
                        'player_id'  => (string) ($row['player_id'] ?? ''),
                        'note'       => (string) ($row['note'] ?? ''),
                        'added_by'   => (string) ($row['added_by'] ?? ''),
                        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                    ];
                }, $rows);
            }
        } catch (\Throwable) {
            // The module works without its database tables; fall through to an empty list.
        }

        return [];
    }

    /**
     * Returns every supported list keyed by list type.
     *
     * @return array<string, list<array{player_id: string, note: string, added_by: string, created_at: string|null}>>
     */
    public function allLists(): array
    {
        $lists = [];

        foreach (self::VALID_LIST_TYPES as $listType) {
            $lists[$listType] = $this->list($listType);
        }

        return $lists;
    }

    /**
     * @return list<string>
     */
    public function listTypes(): array
    {
        return self::VALID_LIST_TYPES;
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
