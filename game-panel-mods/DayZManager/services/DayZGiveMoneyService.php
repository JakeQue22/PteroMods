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
    public function give(mixed $server, string $playerId, int $denomination, string $playerName = '', int $quantity = 1): array
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

        if ($serverId === '') {
            return ['status' => 'error', 'message' => 'Could not resolve server.'];
        }

        if (!$this->tableExists()) {
            return ['status' => 'error', 'message' => 'Give money queue table is unavailable.'];
        }

        $now = date('Y-m-d H:i:s');

        try {
            \Illuminate\Support\Facades\DB::table('dayz_give_money_queue')->insert([
                'server_id'   => $serverId,
                'player_id'   => $playerId,
                'player_name' => $playerName !== '' ? $playerName : $playerId,
                'item_class'  => $itemClass,
                'quantity'    => $quantity,
                'status'      => 'pending',
                'note'        => $denomination . ' coin(s) × ' . $quantity,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        // Best-effort: write a JSON queue file to the server so a server-side
        // mod can fulfil the action when the player next connects.
        $this->writeQueueFile($server, $serverId, $playerId, $itemClass, $quantity);

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
            return \Illuminate\Support\Facades\DB::table('dayz_give_money_queue')
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

    private function writeQueueFile(mixed $server, string $serverId, string $playerId, string $itemClass, int $quantity): void
    {
        try {
            // Read the existing queue file (if any) and merge.
            $path = self::QUEUE_FILE_DIR . '/give_money_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $playerId) . '.json';
            $existing = $this->gateway->readFile($server, $path);
            $queue = [];

            if (is_string($existing) && $existing !== '') {
                $decoded = json_decode($existing, true);

                if (is_array($decoded)) {
                    $queue = $decoded;
                }
            }

            $queue[] = [
                'server_id'  => $serverId,
                'player_id'  => $playerId,
                'item_class' => $itemClass,
                'quantity'   => $quantity,
                'queued_at'  => date('Y-m-d H:i:s'),
            ];

            $this->gateway->writeFile(
                $server,
                $path,
                json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
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
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_give_money_queue');
        } catch (Throwable) {
            return false;
        }
    }
}
