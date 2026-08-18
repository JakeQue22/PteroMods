<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Queues a "give money" action for a DayZ player.
 *
 * Supported denominations map to DayZ item class names:
 *   1   coin   → MoneyRuble1
 *   5   coins  → MoneyRuble5
 *   10  coins  → MoneyRuble10
 *   25  coins  → MoneyRuble25
 *   50  coins  → MoneyRuble50
 *   100 coins  → MoneyRuble100
 *
 * When the action is queued, JSON is written under
 * /profiles/PteroMods/give_money_<uid>.json (falling back to player_id, and
 * mirrored to both keys when they differ) so a server-side bridge can read and
 * fulfil it. Queue entries also carry an optional signed callback path so the
 * in-game bridge can acknowledge delivery immediately instead of waiting for
 * the panel to reconcile the JSON file on the next read. The bundled mission
 * script lives at
 * game-panel-mods/DayZManager/assets/bridge/pteromods_give_money.c.
 */
final class DayZGiveMoneyService
{
    private const TABLE = 'dayz_give_money_queue';
    private const CACHE_SECONDS = 120;
    private const BRIDGE_FULFIL_PATH_TEMPLATE = '/api/server/%s/dayz/player-actions/give-money/%d/bridge-fulfil?signature=%s';

    private const DENOMINATIONS = [1, 5, 10, 25, 50, 100];

    private const CLASS_MAP = [
        1   => 'MoneyRuble1',
        5   => 'MoneyRuble5',
        10  => 'MoneyRuble10',
        25  => 'MoneyRuble25',
        50  => 'MoneyRuble50',
        100 => 'MoneyRuble100',
    ];

    private const QUEUE_FILE_DIR = '/profiles/PteroMods';

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
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
            return ['status' => 'error', 'message' => 'Invalid denomination. Must be 1, 5, 10, 25, 50, or 100.'];
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

        $this->reconcileFulfilled($server, $serverId, $playerId, $playerUid);
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
        $this->forgetPendingCache($serverId);

        return [
            'status'     => 'queued',
            'id'         => (int) $queueId,
            'player_id'  => $playerId,
            'item_class' => $itemClass,
            'quantity'   => $quantity,
            'message'    => $quantity . '× ' . $itemClass . ' queued for ' . ($playerName ?: $playerId) . '. Online players receive it immediately; offline players receive it after reconnecting.',
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

        $this->reconcilePendingPlayers($server, $serverId);

        /** @var list<array<string, mixed>>|null $rows */
        $rows = $this->staleCache->remember(
            'pteromods.dayz.give_money.pending.' . md5($serverId),
            self::CACHE_SECONDS,
            self::CACHE_SECONDS * 20,
            fn (): array => $this->loadPending($serverId),
            [],
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Removes a fulfilled queue entry after the server-side mod has delivered it.
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
            $row = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->first();

            if ($row === null) {
                return ['status' => 'ok', 'id' => $queueId, 'message' => 'Queue entry already removed.'];
            }

            $entry = (array) $row;

            $deleted = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->delete();

            $this->forgetPendingCache($serverId);

            if ($deleted < 1) {
                return ['status' => 'ok', 'id' => $queueId, 'message' => 'Queue entry already removed.'];
            }

            $playerId = trim((string) ($entry['player_id'] ?? ''));
            $playerUid = trim((string) ($entry['player_uid'] ?? ''));

            if ($playerId !== '') {
                $this->syncQueueFile($server, $serverId, $playerId, $playerUid);
            }

            $this->logFulfilledRemoval($serverId, $queueId, $entry);

            return ['status' => 'removed', 'id' => $queueId, 'message' => 'Queue entry removed after delivery.'];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Fulfilment callback for the in-game bridge. This skips panel auth and
     * only accepts a queue-specific signature embedded into the queue file.
     *
     * @return array<string, mixed>
     */
    public function markFulfilledFromBridge(string $serverId, int $queueId, string $signature): array
    {
        $serverId = trim($serverId);

        if ($serverId === '' || $queueId <= 0 || !$this->tableExists()) {
            return ['status' => 'error', 'message' => 'That give money queue entry could not be found.'];
        }

        $signature = strtolower(trim($signature));

        if ($signature === '') {
            return ['status' => 'error', 'message' => 'Missing bridge signature.'];
        }

        try {
            $row = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->first();

            if ($row === null) {
                return ['status' => 'ok', 'id' => $queueId, 'message' => 'Queue entry already removed.'];
            }

            $entry = (array) $row;
            $expected = $this->bridgeCallbackSignature($entry);

            if ($expected === '' || !hash_equals($expected, $signature)) {
                return ['status' => 'error', 'message' => 'Invalid bridge signature.'];
            }

            $deleted = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('id', $queueId)
                ->delete();

            $this->forgetPendingCache($serverId);

            if ($deleted < 1) {
                return ['status' => 'ok', 'id' => $queueId, 'message' => 'Queue entry already removed.'];
            }

            $this->logFulfilledRemoval($serverId, $queueId, $entry);

            return ['status' => 'removed', 'id' => $queueId, 'message' => 'Queue entry removed after delivery.'];
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

            $this->forgetPendingCache($serverId);

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
            $fileKeys = $this->queueFileKeys($playerId, $playerUid);
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
                ->map(function ($row) use ($serverId, $playerId, $playerUid): array {
                    $entry = [
                        'queue_id'   => (int) ($row->id ?? 0),
                        'server_id'  => (string) ($row->server_id ?? $serverId),
                        'player_id'  => (string) ($row->player_id ?? $playerId),
                        'player_uid' => (string) (($row->player_uid ?? '') !== '' ? $row->player_uid : ($playerUid !== '' ? $playerUid : $playerId)),
                        'item_class' => (string) ($row->item_class ?? ''),
                        'quantity'   => max(1, (int) ($row->quantity ?? 1)),
                        'queued_at'  => (string) (($row->created_at ?? '') !== '' ? $row->created_at : date('Y-m-d H:i:s')),
                    ];
                    $callback = $this->bridgeCallbackTarget($entry);

                    if ($callback !== null) {
                        $entry['callback_base_url'] = $callback['base_url'];
                        $entry['callback_path'] = $callback['path'];
                    }

                    return $entry;
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function reconcilePendingPlayers(mixed $server, string $serverId): void
    {
        try {
            $players = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('status', 'pending')
                ->select(['player_id', 'player_uid'])
                ->distinct()
                ->get();

            foreach ($players as $player) {
                $playerId = trim((string) ($player->player_id ?? ''));
                $playerUid = trim((string) ($player->player_uid ?? ''));

                if ($playerId !== '') {
                    $this->reconcileFulfilled($server, $serverId, $playerId, $playerUid);
                }
            }
        } catch (Throwable) {
            // Best-effort reconciliation; the queue file remains authoritative.
        }
    }

    private function reconcileFulfilled(
        mixed $server,
        string $serverId,
        string $playerId,
        string $playerUid,
    ): void {
        $decoded = $this->firstReadableQueueFile($server, $playerId, $playerUid);

        if ($decoded === null) {
            return;
        }

        $activeIds = [];

        foreach ($decoded as $entry) {
            if (is_array($entry) && (int) ($entry['queue_id'] ?? 0) > 0) {
                $activeIds[] = (int) $entry['queue_id'];
            }
        }

        if ($decoded !== [] && $activeIds === []) {
            return;
        }

        try {
            $query = \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('status', 'pending')
                ->where(function ($query) use ($playerId, $playerUid): void {
                    $query->where('player_id', $playerId);

                    if ($playerUid !== '' && $playerUid !== $playerId) {
                        $query->orWhere('player_uid', $playerUid);
                    }
                });

            if ($activeIds !== []) {
                $query->whereNotIn('id', array_values(array_unique($activeIds)));
            }

            if ($query->delete() > 0) {
                $this->forgetPendingCache($serverId);
            }
        } catch (Throwable) {
            // Best-effort reconciliation; retry on the next queue read/write.
        }
    }

    /**
     * @return list<string>
     */
    private function queueFileKeys(string $playerId, string $playerUid): array
    {
        $playerId = trim($playerId);
        $playerUid = trim($playerUid);
        $primaryKey = $playerUid !== '' && $playerUid !== $playerId ? $playerUid : $playerId;

        return array_values(array_filter(array_unique([$primaryKey, $playerId])));
    }

    /**
     * @return array<int, mixed>|null  null = no file found; [] = file found but empty/cleared; non-empty = active entries
     */
    private function firstReadableQueueFile(mixed $server, string $playerId, string $playerUid): ?array
    {
        foreach ($this->queueFileKeys($playerId, $playerUid) as $key) {
            $raw = $this->gateway->readFileFresh($server, $this->queueFilePath($key));

            if ($raw === null) {
                continue;
            }

            // The file exists on disk.  DayZ's JsonSaveFile writes `null` (not `[]`)
            // when serialising an empty typed array, so treat non-array decoded content
            // as an empty queue rather than skipping to the next key.
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPending(string $serverId): array
    {
        try {
            return \Illuminate\Support\Facades\DB::table(self::TABLE)
                ->where('server_id', $serverId)
                ->where('status', 'pending')
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

    private function forgetPendingCache(string $serverId): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        $key = 'pteromods.dayz.give_money.pending.' . md5($serverId);

        try {
            \Illuminate\Support\Facades\Cache::forget($key);
            \Illuminate\Support\Facades\Cache::forget($key . '.lock');
        } catch (Throwable) {
            // Best-effort cache invalidation only.
        }
    }

    private function serverId(mixed $server): string
    {
        return $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
    }

    private function queueFilePath(string $key): string
    {
        $raw = trim($key);
        $safe = trim((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $raw), '_');

        if ($safe === '') {
            $safe = $raw !== '' ? md5($raw) : 'unknown';
        }

        return self::QUEUE_FILE_DIR . '/give_money_' . $safe . '.json';
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

    /**
     * @param array<string, mixed> $entry
     * @return array{base_url:string, path:string}|null
     */
    private function bridgeCallbackTarget(array $entry): ?array
    {
        $baseUrl = $this->bridgeCallbackBaseUrl();
        $queueId = (int) ($entry['queue_id'] ?? $entry['id'] ?? 0);
        $serverId = trim((string) ($entry['server_id'] ?? ''));
        $clientServerId = $serverId !== ''
            ? $this->context->clientIdentifier(['uuidShort' => $serverId], $serverId)
            : '';
        $signature = $this->bridgeCallbackSignature($entry);

        if ($baseUrl === '' || $queueId <= 0 || $clientServerId === '' || $signature === '') {
            return null;
        }

        return [
            'base_url' => $baseUrl,
            'path' => sprintf(self::BRIDGE_FULFIL_PATH_TEMPLATE, rawurlencode($clientServerId), $queueId, rawurlencode($signature)),
        ];
    }

    private function bridgeCallbackBaseUrl(): string
    {
        $candidates = [];

        try {
            if (function_exists('request')) {
                $request = request();

                if (is_object($request) && method_exists($request, 'getSchemeAndHttpHost')) {
                    $base = trim((string) $request->getSchemeAndHttpHost(), '/');

                    if ($base !== '') {
                        $basePath = '';

                        if (method_exists($request, 'getBasePath')) {
                            $basePath = trim((string) $request->getBasePath(), '/');
                        }

                        $candidates[] = $base . ($basePath !== '' ? '/' . $basePath : '');
                    }
                }
            }
        } catch (Throwable) {
            // Fall through to config()/env() below.
        }

        try {
            if (function_exists('config')) {
                $configured = trim((string) config('app.url', ''), '/');

                if ($configured !== '') {
                    $candidates[] = $configured;
                }
            }
        } catch (Throwable) {
            // Ignore config() failures.
        }

        $env = trim((string) getenv('APP_URL'), '/');

        if ($env !== '') {
            $candidates[] = $env;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && preg_match('#^https?://#i', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function bridgeCallbackSignature(array $entry): string
    {
        $secret = $this->bridgeCallbackSecret();
        $queueId = (int) ($entry['queue_id'] ?? $entry['id'] ?? 0);
        $serverId = trim((string) ($entry['server_id'] ?? ''));
        $playerId = trim((string) ($entry['player_id'] ?? ''));
        $playerUid = trim((string) ($entry['player_uid'] ?? ''));
        $itemClass = trim((string) ($entry['item_class'] ?? ''));
        $quantity = max(1, (int) ($entry['quantity'] ?? 1));

        if ($secret === '' || $queueId <= 0 || $serverId === '' || $playerId === '' || $itemClass === '') {
            return '';
        }

        $payload = implode(':', [$queueId, $serverId, $playerId, $playerUid, $itemClass, $quantity]);

        return hash_hmac('sha256', $payload, $secret);
    }

    private function bridgeCallbackSecret(): string
    {
        $secret = trim((string) getenv('PTEROMODS_GIVE_MONEY_BRIDGE_SECRET'));

        if ($secret !== '') {
            return $secret;
        }

        $secret = '';

        try {
            if (function_exists('config')) {
                $secret = trim((string) config('app.key', ''));
            }
        } catch (Throwable) {
            $secret = '';
        }

        if ($secret === '') {
            $secret = trim((string) getenv('APP_KEY'));
        }

        return $secret;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function logFulfilledRemoval(string $serverId, int $queueId, array $entry): void
    {
        $context = [
            'server_id' => $serverId,
            'queue_id' => $queueId,
            'player_id' => (string) ($entry['player_id'] ?? ''),
            'player_uid' => (string) ($entry['player_uid'] ?? ''),
            'player_name' => (string) ($entry['player_name'] ?? ''),
            'item_class' => (string) ($entry['item_class'] ?? ''),
            'quantity' => (int) ($entry['quantity'] ?? 1),
        ];

        try {
            if (class_exists('Illuminate\\Support\\Facades\\Log')) {
                \Illuminate\Support\Facades\Log::info('DayZ give money queue entry fulfilled and removed.', $context);

                return;
            }
        } catch (Throwable) {
            // Fall back to PHP's error log below.
        }

        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('DayZ give money queue entry fulfilled and removed. ' . ($encoded !== false ? $encoded : ''));
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
