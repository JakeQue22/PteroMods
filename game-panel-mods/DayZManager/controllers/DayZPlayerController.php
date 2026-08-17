<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZAdminActionLogService;
use GamePanelMods\DayZManager\Services\DayZBankingService;
use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
use GamePanelMods\DayZManager\Services\DayZGiveMoneyService;
use GamePanelMods\DayZManager\Services\DayZInventoryItemResolverService;
use GamePanelMods\DayZManager\Services\DayZLiveMapService;
use GamePanelMods\DayZManager\Services\DayZObservedPlayerService;
use GamePanelMods\DayZManager\Services\DayZPlayerDirectoryService;
use GamePanelMods\DayZManager\Services\DayZPlayerService;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use GamePanelMods\DayZManager\Services\DayZVppAdminService;
use Throwable;

/**
 * Manages DayZ ban, whitelist, and priority player lists.
 */
final class DayZPlayerController
{
    private const REMOVE_PLAYER_EMAIL = 'jake@quantumonline.co.uk';

    public function __construct(
        private readonly DayZPlayerService $service = new DayZPlayerService(),
        private readonly DayZObservedPlayerService $observedPlayers = new DayZObservedPlayerService(),
        private readonly DayZLiveMapService $liveMap = new DayZLiveMapService(),
        private readonly DayZPlayerDirectoryService $directory = new DayZPlayerDirectoryService(),
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZVppAdminService $vppAdmin = new DayZVppAdminService(),
        private readonly DayZGiveMoneyService $giveMoneySvc = new DayZGiveMoneyService(),
        private readonly DayZInventoryItemResolverService $inventoryResolver = new DayZInventoryItemResolverService(),
        private readonly DayZBankingService $banking = new DayZBankingService(),
        private readonly DayZAdminActionLogService $adminLog = new DayZAdminActionLogService(),
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
            $mapName = (string) ($snapshot['map'] ?? 'ChernarusPlus');
            $persisted = $this->directory->directory($resolved['model'], $mapName, $livePlayers);
            $playerLists = $this->service->allLists();
            $superadminIds = $this->vppAdmin->list($resolved['model']);
            $giveMoneyQueue = $this->giveMoneySvc->pending($resolved['model']);
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
                'persistence_source_paths' => $persisted['source_paths'] ?? [],
                'map_definition' => $snapshot['map_definition'] ?? null,
                'superadmin_ids' => $superadminIds,
            ];
        }

        return $this->renderer->render('players', [
            'player_lists' => $playerLists,
            'persisted_players' => $persisted['players'] ?? [],
            'live_players' => $livePlayers,
            'persistence_status' => $persisted['status'] ?? 'not_found',
            'persistence_source_path' => $persisted['source_path'] ?? null,
            'persistence_source_paths' => $persisted['source_paths'] ?? [],
            'map_definition' => $snapshot['map_definition'] ?? ['name' => 'ChernarusPlus', 'locations' => []],
            'online_count' => count($livePlayers),
            'superadmin_ids' => $superadminIds,
            'give_money_queue' => $giveMoneyQueue,
            'protected_steam64' => DayZVppAdminService::PROTECTED_STEAM64,
            'can_remove_players' => $this->actorEmail() === self::REMOVE_PLAYER_EMAIL,
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
        $nickname = $this->context->stringInput('nickname');

        $result = $this->service->add($listType, $playerId, $note, $addedBy, $nickname, $model);

        if ($listType === 'ban' && ($result['status'] ?? '') === 'saved') {
            $this->adminLog->log($model, 'Ban', $this->actorName(), $nickname, $playerId, array_filter([
                'Note' => $note,
            ], static fn (string $value): bool => $value !== ''));
        }

        return $result;
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
    public function resetObserved(mixed $server = null, string $id = ''): array
    {
        try {
            $model = $this->authoriseManage($server);
            $playerId = $id !== '' ? $id : $this->context->stringInput('player_id');

            return $this->observedPlayers->reset($model, $playerId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreObserved(mixed $server = null, string $id = ''): array
    {
        try {
            $model = $this->authoriseManage($server);
            $playerId = $id !== '' ? $id : $this->context->stringInput('player_id');
            $backupId = $this->context->stringInput('backup_id');

            return $this->observedPlayers->restore($model, $playerId, $backupId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function kick(mixed $server = null): array
    {
        try {
            $model = $this->authoriseManage($server);
            $playerId = $this->context->stringInput('player_id');

            $result = $this->observedPlayers->kick($model, $playerId);

            if (($result['status'] ?? '') === 'dispatched') {
                $this->adminLog->log($model, 'Kick', $this->actorName(), $this->context->stringInput('player_name'), $playerId);
            }

            return $result;
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Adds a player as a VPP SuperAdmin.
     *
     * @return array<string, mixed>
     */
    public function addSuperadmin(mixed $server = null): array
    {
        try {
            $model = $this->authoriseManage($server);
            $steam64  = $this->context->stringInput('steam64');
            $nickname = $this->context->stringInput('nickname');

            return $this->vppAdmin->add($model, $steam64, $nickname);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Removes a player from the VPP SuperAdmins list.
     *
     * @return array<string, mixed>
     */
    public function removeSuperadmin(mixed $server = null, string $steam64 = ''): array
    {
        try {
            $model   = $this->authoriseManage($server);
            $steam64 = $steam64 !== '' ? $steam64 : $this->context->stringInput('steam64');

            return $this->vppAdmin->remove($model, $steam64);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Queues money (as DayZ item class names) for an offline player.
     *
     * @return array<string, mixed>
     */
    public function giveMoney(mixed $server = null): array
    {
        try {
            $model = $this->authoriseManage($server);
            $playerId   = $this->context->stringInput('player_id');
            $denomination = (int) $this->context->stringInput('denomination');
            $quantity     = max(1, (int) $this->context->stringInput('quantity') ?: 1);
            $playerName   = $this->context->stringInput('player_name');
            $playerUid    = $this->context->stringInput('player_uid');

            $result = $this->giveMoneySvc->give($model, $playerId, $denomination, $playerName, $quantity, $playerUid);

            if (($result['status'] ?? '') === 'queued') {
                $this->adminLog->log($model, 'Give Money', $this->actorName(), $playerName, $playerId, [
                    'Item' => (string) ($result['item_class'] ?? ''),
                    'Quantity' => (string) ($result['quantity'] ?? $quantity),
                    'Denomination' => (string) $denomination,
                ]);
            }

            return $result;
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Sets a player's LB Banking balance to a new amount.
     *
     * @return array<string, mixed>
     */
    public function alterBankMoney(mixed $server = null): array
    {
        try {
            $model = $this->authoriseManage($server);
            $steam64 = $this->context->stringInput('steam64');
            $playerName = $this->context->stringInput('player_name');
            $amountInput = trim($this->context->stringInput('amount'));

            if ($amountInput === '' || !is_numeric($amountInput)) {
                return ['status' => 'error', 'message' => 'A numeric bank money amount is required.'];
            }

            $result = $this->banking->setBalance($model, $steam64, (float) $amountInput);

            if (($result['status'] ?? '') === 'saved') {
                $this->adminLog->log($model, 'Alter Bank Money', $this->actorName(), $playerName, $steam64, [
                    'Previous currentMoney' => $result['previous_money'] ?? 'unknown',
                    'New currentMoney' => $result['current_money'] ?? '',
                ]);
            }

            return $result;
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveInventory(mixed $server = null): array
    {
        try {
            $this->authoriseManage($server);
            $items = $this->context->input('items', []);
            $forceRefresh = filter_var($this->context->input('refresh', false), FILTER_VALIDATE_BOOL);

            if (!is_array($items)) {
                return ['status' => 'error', 'message' => 'Inventory items payload must be an array.', 'items' => []];
            }

            return [
                'status' => 'ok',
                'items' => $this->inventoryResolver->resolveBatch($items, $forceRefresh),
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage(), 'items' => []];
        }
    }

    /**
     * Removes a queued give-money entry.
     *
     * @return array<string, mixed>
     */
    public function removeGiveMoney(mixed $server = null, string $id = ''): array
    {
        try {
            $model = $this->authoriseManage($server);
            $queueId = (int) ($id !== '' ? $id : $this->context->stringInput('id'));

            return $this->giveMoneySvc->remove($model, $queueId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Marks a give-money queue entry as delivered.
     * Called by the server-side mod callback or manually from the UI.
     *
     * @return array<string, mixed>
     */
    public function fulfillGiveMoney(mixed $server = null, string $id = ''): array
    {
        try {
            $model = $this->authoriseManage($server);
            $queueId = (int) ($id !== '' ? $id : $this->context->stringInput('id'));

            return $this->giveMoneySvc->markFulfilled($model, $queueId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Bridge-only give-money fulfilment callback. It is authenticated by the
     * queue-specific HMAC in the callback URL instead of panel session auth.
     *
     * @return array<string, mixed>
     */
    public function bridgeFulfillGiveMoney(mixed $server = null, string $id = ''): array
    {
        try {
            $serverId = is_scalar($server)
                ? trim((string) $server)
                : $this->context->routeServerParameter();
            $queueId = (int) ($id !== '' ? $id : $this->context->stringInput('id'));
            $signature = $this->context->stringInput('signature');

            return $this->giveMoneySvc->markFulfilledFromBridge($serverId, $queueId, $signature);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Permanently removes a player from the players list.
     * Only the configured REMOVE_PLAYER_EMAIL account may call this.
     *
     * @return array<string, mixed>
     */
    public function removePlayer(mixed $server = null, string $id = ''): array
    {
        try {
            if ($this->actorEmail() !== self::REMOVE_PLAYER_EMAIL) {
                return ['status' => 'error', 'message' => 'You do not have permission to remove players.'];
            }

            $model = $this->authoriseManage($server);
            $playerId          = $id !== '' ? $id : $this->context->stringInput('player_id');
            $playerName        = $this->context->stringInput('player_name');
            $selectedPlayerId  = $this->context->stringInput('selected_player_id');

            return $this->observedPlayers->removePlayer($model, $playerId, $playerName, $this->actorEmail(), $selectedPlayerId);
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

    private function actorEmail(): string
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Auth')) {
            return '';
        }

        try {
            $user = \Illuminate\Support\Facades\Auth::user();

            return $user !== null ? strtolower(trim((string) ($user->email ?? ''))) : '';
        } catch (Throwable) {
            return '';
        }
    }
}
