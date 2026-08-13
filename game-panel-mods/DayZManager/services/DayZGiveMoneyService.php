<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Queues a "give money" action for an offline DayZ player.
 *
 * Supported denominations map to DayZ item class names:
 *   1   coin  → MoneyRuble1
 *   50  coins → MoneyRuble50
 *   100 coins → MoneyRuble100
 *
 * When the action is queued, a JSON file is written to the server at
 * /profiles/PteroMods/give_money_<player_id>.json so a server-side mod can
 * read and fulfil it when the player next connects.  The row in the panel DB
 * acts as the authoritative record; the file is best-effort.
 */
final class DayZGiveMoneyService
{
    private const TABLE = 'dayz_give_money_queue';

    private const DENOMINATIONS = [1, 50, 100];

    private const CLASS_MAP = [
        1   => 'MoneyRuble1',
        50  => 'MoneyRuble50',
        100 => 'MoneyRuble100',
    ];

    private const QUEUE_FILE_DIR = '/profiles/PteroMods';

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * Queues money for an offline player.
     *
     * @return array<string, mixed>
     */
    public function give(mixed $server, string $playerId, int $denomination, string $playerName = '', int $quantity = 1, string $playerUid = ''): array
    {
        $playerId = trim($playerId);

        if ($playerId === '') {
            return ['status' => 'error', 'message' => 'Player ID is required.'];
        }

        if (!in_array($denomination, self::DENOMINATIONS, true)) {
            return ['status' => 'error', 'message' => 'Invalid denomination. Must be 1, 50, or 100.'];
        }

        $quantity = max(1, min(99, $quantity));
        $itemClass = self::CLASS_MAP[$denomination];
        $serverId = $this->serverId($server);
        $playerUid = trim($playerUid);

        if ($serverId === '') {
            return ['status' => 'error', 'message' => 'Could not resolve server.'];
        }

        if (!$this->tableExists()) {
            return ['status' => 'error', 'message' => 'Give money queue table is unavailable.'];
        }

        $now = date('Y-m-d H:i:s');

        $row = [
            'server_id'   => $serverId,
            'player_id'   => $playerId,
            'player_name' => $playerName !== '' ? $playerName : $playerId,
            'item_class'  => $itemClass,
            'quantity'    => $quantity,
            'status'      => 'pending',
            'note'        => $denomination . ' coin(s) × ' . $quantity,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        // Include player_uid when the column is present.
        if ($playerUid !== '') {
            $row['player_uid'] = $playerUid;
        }

        try {
            $queueId = \Illuminate\Support\Facades\DB::table(self::TABLE)->insertGetId($row);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        // Best-effort: sync the JSON queue file on the server so a server-side
        // mod can fulfil the action when the player next connects.
        $this->syncQueueFile($server, $serverId, $playerId, $playerUid);

        return [
            'status'     => 'queued',
            'id'         => (int) $queueId,
            'player_id'  => $playerId,
            'item_class' => $itemClass,
            'quantity'   => $quantity,
            'message'    => $quantity . '× ' . $itemClass . ' queued for ' . ($playerName ?: $playerId) . '. The items will be awarded when the server processes the queue.',
        ];
    }

    /**
     * Returns all pending give-money entries for the given server.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(mixed $server): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '' || !$this->tableExists()) {
            return [];
        }

        try {
            return \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(function ($row): array {
                    $entry = (array) $row;
                    $entry['created_at_display'] = $this->displayTimestamp($entry['created_at'] ?? null);

                    return $entry;
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Marks a pending queue entry as 'delivered' (called by the server-side mod
     * via the panel API, or manually from the UI).
     *
     * @return array<string, mixed>
     */
    public function markFulfilled(mixed $server, int $queueId): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return ['status' => 'error', 'message' => 'Could not resolve server.'];
        }

        if ($queueId <= 0 || !$this->tableExists()) {
            return ['status' => 'error', 'message' => 'That give money queue entry could not be found.'];
        }

        try {
            $updated = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->where('status', 'pending')
                ->update(['status' => 'delivered', 'updated_at' => date('Y-m-d H:i:s')]);

            if ($updated < 1) {
                // Already delivered or not found — still a success from the caller's view.
                return ['status' => 'ok', 'id' => $queueId, 'message' => 'Queue entry already marked as delivered.'];
            }

            // Re-sync the queue file for this player so it no longer contains
            // this entry.
            $row = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->first();

            if ($row !== null) {
                $entry = (array) $row;
                $playerId = trim((string) ($entry['player_id'] ?? ''));
                $playerUid = trim((string) ($entry['player_uid'] ?? ''));

                if ($playerId !== '') {
                    $this->syncQueueFile($server, $serverId, $playerId, $playerUid);
                }
            }

            return ['status' => 'delivered', 'id' => $queueId, 'message' => 'Queue entry marked as delivered.'];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Removes a pending queue entry and rewrites the server-side queue file.
     *
     * @return array<string, mixed>
     */
    public function remove(mixed $server, int $queueId): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return ['status' => 'error', 'message' => 'Could not resolve server.'];
        }

        if ($queueId <= 0 || !$this->tableExists()) {
            return ['status' => 'error', 'message' => 'That give money queue entry could not be found.'];
        }

        try {
            $row = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->first();

            if ($row === null) {
                return ['status' => 'error', 'message' => 'That give money queue entry could not be found.'];
            }

            $entry = (array) $row;

            $deleted = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->delete();

            if ($deleted < 1) {
                return ['status' => 'error', 'message' => 'That give money queue entry could not be removed.'];
            }

            $playerId = trim((string) ($entry['player_id'] ?? ''));
            $playerUid = trim((string) ($entry['player_uid'] ?? ''));

            if ($playerId !== '') {
                $this->syncQueueFile($server, $serverId, $playerId, $playerUid);
            }

            return [
                'status'  => 'removed',
                'id'      => $queueId,
                'message' => 'Removed that queued give money entry.',
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    private function syncQueueFile(mixed $server, string $serverId, string $playerId, string $playerUid): void
    {
        try {
            $playerUid = trim($playerUid);
            $uidKey = $playerUid !== '' && $playerUid !== $playerId ? $playerUid : $playerId;
            $fileKeys = array_values(array_unique([$uidKey, $playerId]));
            $queue = $this->pendingQueueFileEntries($serverId, $playerId, $playerUid);

            foreach ($fileKeys as $key) {
                $path = $this->queueFilePath($key);

                if ($queue === []) {
                    $this->gateway->deletePath($server, $path);
                    continue;
                }

                $encoded = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if ($encoded !== false) {
                    $this->gateway->writeFile($server, $path, $encoded);
                }
            }
        } catch (Throwable) {
            // Best-effort only; the DB record is the source of truth.
        }
    }

    /**
     * @return list<array{queue_id:int, server_id:string, player_id:string, player_uid:string, item_class:string, quantity:int, queued_at:string}>
     */
    private function pendingQueueFileEntries(string $serverId, string $playerId, string $playerUid): array
    {
        if ($serverId === '' || $playerId === '') {
            return [];
        }

        try {
            return \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('status', 'pending')
                ->where(function ($query) use ($playerId, $playerUid): void {
                    $query->where('player_id', $playerId);

                    if ($playerUid !== '' && $playerUid !== $playerId) {
                        $query->orWhere('player_uid', $playerUid);
                    }
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($row): array => [
                    'queue_id'   => (int) ($row->id ?? 0),
                    'server_id'  => (string) ($row->server_id ?? $serverId),
                    'player_id'  => (string) ($row->player_id ?? $playerId),
                    'player_uid' => (string) (($row->player_uid ?? '') !== '' ? $row->player_uid : $playerId),
                    'item_class' => (string) ($row->item_class ?? ''),
                    'quantity'   => max(1, (int) ($row->quantity ?? 1)),
                    'queued_at'  => (string) (($row->created_at ?? '') !== '' ? $row->created_at : date('Y-m-d H:i:s')),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function serverId(mixed $server): string
    {
        return $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
    }

    private function queueFilePath(string $key): string
    {
        return self::QUEUE_FILE_DIR . '/give_money_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    }

    private function displayTimestamp(mixed $timestamp): ?string
    {
        $text = trim((string) ($timestamp ?? ''));

        if ($text === '') {
            return null;
        }

        $time = strtotime($text);

        return $time === false ? $text : date('d-m-Y H:i:s', $time);
    }

    private function tableExists(): bool
    {
        $tableFound = false;

        try {
            if (class_exists('Illuminate\\Support\\Facades\\Schema')
                && \Illuminate\Support\Facades\Schema::hasTable(self::TABLE)
            ) {
                $tableFound = true;
            }
        } catch (Throwable) {
            // fall through
        }

        if (!$tableFound) {
            if (!class_exists('Illuminate\\Support\\Facades\\DB')) {
                return false;
            }

            try {
                \Illuminate\Support\Facades\DB::statement($this->createTableSql());
                $tableFound = true;
            } catch (Throwable) {
                // fall through
            }

            if (!$tableFound) {
                try {
                    \Illuminate\Support\Facades\DB::table(self::TABLE)->limit(1)->get();
                    $tableFound = true;
                } catch (Throwable) {
                    return false;
                }
            }
        }

        // Ensure the player_uid column exists (added in a later revision).
        // The static flag keeps this best-effort check to once per PHP worker.
        static $uidColumnChecked = false;

        if (!$uidColumnChecked) {
            $uidColumnChecked = true;

            try {
                if (class_exists('Illuminate\\Support\\Facades\\Schema')
                    && !\Illuminate\Support\Facades\Schema::hasColumn(self::TABLE, 'player_uid')
                ) {
                    \Illuminate\Support\Facades\DB::statement(
                        "ALTER TABLE `dayz_give_money_queue` ADD COLUMN `player_uid` VARCHAR(128) NOT NULL DEFAULT '' AFTER `player_id`"
                    );
                }
            } catch (Throwable) {
                // Best-effort column migration; non-fatal.
            }
        }

        return true;
    }

    private function createTableSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS `dayz_give_money_queue` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`   VARCHAR(64)   NOT NULL,
            `player_id`   VARCHAR(64)   NOT NULL,
            `player_uid`  VARCHAR(128)  NOT NULL DEFAULT '',
            `player_name` VARCHAR(255)  NOT NULL DEFAULT '',
            `item_class`  VARCHAR(64)   NOT NULL,
            `quantity`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `status`      VARCHAR(16)   NOT NULL DEFAULT 'pending',
            `note`        VARCHAR(255)  NOT NULL DEFAULT '',
            `created_at`  TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`  TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_dayz_give_money_queue_server_player` (`server_id`, `player_id`),
            KEY `idx_dayz_give_money_queue_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }
}
