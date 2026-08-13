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
            \Illuminate\Support\Facades\DB::table(self::TABLE)->insert($row);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        // Best-effort: write a JSON queue file to the server so a server-side
        // mod can fulfil the action when the player next connects.
        $this->writeQueueFile($server, $serverId, $playerId, $playerUid, $itemClass, $quantity);

        return [
            'status'     => 'queued',
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
                ->limit(200)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function writeQueueFile(mixed $server, string $serverId, string $playerId, string $playerUid, string $itemClass, int $quantity): void
    {
        try {
            // Use the DayZ UID as the primary filename key when available, since
            // server-side mods identify players by UID.  Retain the steam64-named
            // file as a secondary fallback so older mod versions still work.
            $uidKey   = $playerUid !== '' && $playerUid !== $playerId ? $playerUid : $playerId;
            $fileKeys = array_values(array_unique([$uidKey, $playerId]));

            $primaryPath = self::QUEUE_FILE_DIR . '/give_money_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $uidKey) . '.json';
            $existing    = $this->gateway->readFile($server, $primaryPath);
            $queue       = [];

            if (is_string($existing) && $existing !== '') {
                $decoded = json_decode($existing, true);

                if (is_array($decoded)) {
                    $queue = $decoded;
                }
            }

            $queue[] = [
                'server_id'  => $serverId,
                'player_id'  => $playerId,
                'player_uid' => $playerUid !== '' ? $playerUid : $playerId,
                'item_class' => $itemClass,
                'quantity'   => $quantity,
                'queued_at'  => date('Y-m-d H:i:s'),
            ];

            $encoded = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Write both the primary (UID) file and the secondary (steam64) file
            // with the same content so they stay in sync.
            foreach ($fileKeys as $key) {
                $path = self::QUEUE_FILE_DIR . '/give_money_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
                $this->gateway->writeFile($server, $path, $encoded);
            }
        } catch (Throwable) {
            // Best-effort only; the DB record is the source of truth.
        }
    }

    private function serverId(mixed $server): string
    {
        return $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
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
        // This runs at most once per request thanks to the static flag.
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
