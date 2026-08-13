<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Manages DayZ ban, whitelist, and priority player lists.
 */
final class DayZPlayerService
{
    private const VALID_LIST_TYPES = ['ban', 'whitelist', 'priority'];
    private const LIST_FILES = [
        'ban' => '/ban.txt',
        'whitelist' => '/whitelist.txt',
        'priority' => '/priority.txt',
    ];

    private const CACHE_SECONDS = 120;

    public function __construct(
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * @return list<array{player_id: string, nickname: string, note: string, added_by: string, created_at: string|null}>
     */
    public function list(string $listType): array
    {
        $this->assertValidListType($listType);
        $key = 'pteromods.dayz.players.' . $listType;
        $rows = $this->staleCache->remember(
            $key,
            self::CACHE_SECONDS,
            self::CACHE_SECONDS * 20,
            fn (): array => $this->fetchList($listType),
            [],
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns every supported list keyed by list type.
     *
     * @return array<string, list<array{player_id: string, nickname: string, note: string, added_by: string, created_at: string|null}>>
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
    public function add(string $listType, string $playerId, string $note = '', string $addedBy = '', string $nickname = '', mixed $server = null): array
    {
        $this->assertValidListType($listType);
        $this->assertValidPlayerId($playerId);
        $nickname = $this->normalizeNickname($nickname);

        if (!$this->tableAvailable()) {
            return ['status' => 'error', 'message' => 'Player-list storage is not available.'];
        }

        try {
            $values = [
                'note' => trim($note),
                'added_by' => trim($addedBy),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($this->nicknameColumnAvailable()) {
                $values['nickname'] = $nickname;
            }

            \Illuminate\Support\Facades\DB::table('dayz_player_lists')->updateOrInsert(
                ['list_type' => $listType, 'player_id' => $playerId],
                $values,
            );
        } catch (\Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        $this->forgetListCache($listType);
        $synced = $this->syncListFile($listType, $server);

        return [
            'status'    => 'saved',
            'action'    => 'add',
            'list_type' => $listType,
            'player_id' => $playerId,
            'nickname'  => $nickname,
            'note'      => $note,
            'added_by'  => $addedBy,
            'file_synced' => $synced,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function remove(string $listType, string $playerId, mixed $server = null): array
    {
        $this->assertValidListType($listType);
        $this->assertValidPlayerId($playerId);

        if (!$this->tableAvailable()) {
            return ['status' => 'error', 'message' => 'Player-list storage is not available.'];
        }

        try {
            \Illuminate\Support\Facades\DB::table('dayz_player_lists')
                ->where('list_type', $listType)
                ->where('player_id', $playerId)
                ->delete();
        } catch (\Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        $this->forgetListCache($listType);
        $synced = $this->syncListFile($listType, $server);

        return [
            'status'    => 'deleted',
            'action'    => 'remove',
            'list_type' => $listType,
            'player_id' => $playerId,
            'file_synced' => $synced,
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

    /**
     * @return list<array{player_id: string, nickname: string, note: string, added_by: string, created_at: string|null}>
     */
    private function fetchList(string $listType): array
    {
        try {
            if ($this->tableAvailable()) {
                $rows = \Illuminate\Support\Facades\DB::table('dayz_player_lists')
                    ->where('list_type', $listType)
                    ->orderBy('player_id')
                    ->get()
                    ->toArray();

                return array_map(static function ($row): array {
                    $row = (array) $row;

                    return [
                        'player_id'  => (string) ($row['player_id'] ?? ''),
                        'nickname'   => (string) ($row['nickname'] ?? ''),
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

    private function tableAvailable(): bool
    {
        return class_exists('Illuminate\\Support\\Facades\\Schema')
            && class_exists('Illuminate\\Support\\Facades\\DB')
            && \Illuminate\Support\Facades\Schema::hasTable('dayz_player_lists');
    }

    private function nicknameColumnAvailable(): bool
    {
        if (!$this->tableAvailable()) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('dayz_player_lists', 'nickname');
        } catch (\Throwable) {
            return false;
        }
    }

    private function forgetListCache(string $listType): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::forget('pteromods.dayz.players.' . $listType);
            \Illuminate\Support\Facades\Cache::forget('pteromods.dayz.players.' . $listType . '.lock');
        } catch (\Throwable) {
            // Best-effort cache invalidation only.
        }
    }

    private function syncListFile(string $listType, mixed $server): bool
    {
        $path = self::LIST_FILES[$listType] ?? null;

        if ($path === null || $server === null) {
            return false;
        }

        $entries = [];

        foreach ($this->fetchList($listType) as $entry) {
            $playerId = trim((string) ($entry['player_id'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            $nickname = $this->normalizeNickname((string) ($entry['nickname'] ?? ''));
            $entries[$playerId] = $playerId . ($nickname !== '' ? '        // ' . $nickname : '');
        }

        $content = $entries === [] ? '' : implode("\n", array_values($entries)) . "\n";

        return $this->gateway->writeFile($server, $path, $content);
    }

    private function normalizeNickname(string $nickname): string
    {
        $nickname = preg_replace('/\s+/', ' ', trim(str_replace(["\r", "\n"], ' ', $nickname)));

        return trim((string) str_replace('//', '/', $nickname));
    }
}
