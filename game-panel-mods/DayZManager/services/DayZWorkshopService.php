<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\LaunchParameterBuilder;
use PteroMods\Services\DayZ\ModMetaParser;
use PteroMods\Services\DayZ\WorkshopDependencyPlanner;
use PteroMods\Services\DayZ\WorkshopReferenceParser;
use Throwable;

/**
 * Reports the Workshop mods that are actually installed on a server.
 *
 * Mods are discovered from the server container itself: every `@Folder` in the
 * server root is a mod, and its `meta.cpp`/`mod.cpp` files carry the Workshop
 * ID, title, author, and version. The load order and enabled state come from
 * the `-mod=` launch parameter Pterodactyl boots the server with, so the page
 * always mirrors the real installation instead of a static sample list.
 */
final class DayZWorkshopService
{
    /** Upper bound on scanned mod folders, to keep page loads predictable. */
    private const MAX_MODS = 60;

    /** @var array<string, list<array<string, mixed>>> Per-request mod cache. */
    private array $memo = [];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStartupService $startup = new DayZStartupService(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly ModMetaParser $meta = new ModMetaParser(),
    ) {
    }

    /**
     * Ordered folder names of every enabled mod.
     *
     * @return list<string>
     */
    public function enabledFolders(mixed $server = null): array
    {
        $folders = array_map(
            static fn (array $mod): string => ($mod['enabled'] ?? false) ? (string) ($mod['folder_name'] ?? '') : '',
            $this->installedMods($server),
        );

        return array_values(array_filter($folders, static fn (string $folder): bool => $folder !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(mixed $server = null): array
    {
        $server = $this->resolveServer($server);
        $startup = $this->startup->startup($server);
        $installed = $this->installedMods($server);
        $enabled = $this->enabledFolders($server);

        return [
            'mod_directory'          => '/ (server root)',
            'workshop_download_path' => '/steamapps/workshop/content/221100',
            'installed_mods'         => count($installed),
            'enabled_mods'           => count($enabled),
            'server_only_mods'       => $startup['server_mods'] === [] ? '—' : implode(', ', $startup['server_mods']),
            'load_order_source'      => $startup['mods'] === [] ? 'not detected in startup command' : 'startup command (-mod=)',
            'launch_parameters'      => (new LaunchParameterBuilder())->build($enabled),
        ];
    }

    /**
     * Mods installed on the server, ordered by their load order.
     *
     * @return list<array<string, mixed>>
     */
    public function installedMods(mixed $server = null): array
    {
        $server = $this->resolveServer($server);
        $memoKey = $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);

        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $startup = $this->startup->startup($server);
        $loadOrder = $startup['mods'];
        $serverMods = $startup['server_mods'];

        $folders = $this->modFolders($server);

        if ($folders === [] && $loadOrder === [] && $serverMods === []) {
            return $this->memo[$memoKey] = $this->databaseMods();
        }

        $known = [];

        foreach ($folders as $folder) {
            $known[strtolower($folder['name'])] = $folder;
        }

        // Mods referenced by the startup command come first, in load order, so
        // the page shows the same order the game engine uses.
        $ordered = [];

        foreach (array_merge($loadOrder, $serverMods) as $folder) {
            $ordered[strtolower($folder)] ??= $folder;
        }

        foreach ($known as $key => $folder) {
            $ordered[$key] ??= $folder['name'];
        }

        $enabledKeys = array_map('strtolower', $loadOrder);
        $serverOnlyKeys = array_map('strtolower', $serverMods);

        $mods = [];
        $position = 0;

        foreach ($ordered as $key => $name) {
            if ($position >= self::MAX_MODS) {
                break;
            }

            $entry = $known[$key] ?? null;

            $mods[] = $this->describeMod(
                $server,
                $entry === null ? $name : $entry['name'],
                installed: $entry !== null,
                size: $entry['size'] ?? 0,
                enabled: $enabledKeys === [] ? $entry !== null : in_array($key, $enabledKeys, true),
                serverOnly: in_array($key, $serverOnlyKeys, true),
                position: $position,
            );

            $position++;
        }

        return $this->memo[$memoKey] = $mods;
    }

    /**
     * @param array<string, array{dependencies?: list<string>, requires_cf?: bool}> $metadata
     * @return array<string, mixed>
     */
    public function installPlan(string $reference, array $metadata = []): array
    {
        $workshopId = (new WorkshopReferenceParser())->parse($reference);
        $plan = (new WorkshopDependencyPlanner())->buildPlan($workshopId, $metadata + [
            $workshopId => $metadata[$workshopId] ?? ['dependencies' => [], 'requires_cf' => true],
        ]);

        return [
            'workshop_id' => $workshopId,
            'install_order' => $plan,
            'restart_after_update' => true,
            'auto_dependency_installation' => true,
        ];
    }

    /**
     * Queues an update for a mod. Updating downloads new files, which requires
     * SteamCMD on the node, so the module reports the required steps instead of
     * pretending the files changed.
     *
     * @return array<string, string>
     */
    public function update(string $workshopId): array
    {
        return [
            'status'      => 'manual',
            'action'      => 'update',
            'workshop_id' => $workshopId,
            'message'     => 'Re-download the mod with SteamCMD (or your egg\'s update script) and restart the server.',
        ];
    }

    /**
     * Removes a mod: it is dropped from the load order and its folder is
     * deleted from the server when the daemon is reachable.
     *
     * @return array<string, mixed>
     */
    public function remove(string $reference, mixed $server = null, bool $deleteFiles = true): array
    {
        $server = $this->resolveServer($server);
        $mod = $this->findMod($reference, $server);

        if ($mod === null) {
            return ['status' => 'failed', 'action' => 'remove', 'reference' => $reference, 'message' => 'That mod is not installed on this server.'];
        }

        $folder = (string) $mod['folder_name'];
        $result = $this->toggle($reference, false, $server);
        $filesDeleted = false;

        if ($deleteFiles && ($mod['installed'] ?? false)) {
            $filesDeleted = $this->gateway->deletePath($server, '/' . $folder);
            $this->memo = [];
        }

        $result['action'] = 'remove';
        $result['files_deleted'] = $filesDeleted;
        $result['message'] = $filesDeleted
            ? sprintf('Removed %s from the load order and deleted its files.', $folder)
            : sprintf('Removed %s from the load order. Delete the folder in the file manager to free the disk space.', $folder);

        return $result;
    }

    /**
     * Enables or disables a mod by rewriting the `-mod=` load order.
     *
     * @return array<string, mixed>
     */
    public function toggle(string $reference, bool $enabled, mixed $server = null): array
    {
        $server = $this->resolveServer($server);
        $mod = $this->findMod($reference, $server);

        if ($mod === null) {
            return ['status' => 'failed', 'action' => $enabled ? 'enable' : 'disable', 'reference' => $reference, 'message' => 'That mod is not installed on this server.'];
        }

        $folder = (string) $mod['folder_name'];
        $order = $this->enabledFolders($server);
        $order = array_values(array_filter($order, static fn (string $entry): bool => strcasecmp($entry, $folder) !== 0));

        if ($enabled) {
            $order[] = $folder;
        }

        return $this->persistOrder($server, $order, $enabled ? 'enable' : 'disable') + ['folder_name' => $folder];
    }

    /**
     * Persists a new load order.
     *
     * @param list<string> $orderedWorkshopIds Workshop IDs or folder names, in the desired order.
     * @return array<string, mixed>
     */
    public function reorder(array $orderedWorkshopIds, mixed $server = null): array
    {
        $server = $this->resolveServer($server);

        $orderedWorkshopIds = array_values(array_filter(
            array_map('strval', $orderedWorkshopIds),
            static fn (string $id): bool => $id !== '',
        ));

        $enabled = $this->enabledFolders($server);
        $order = [];

        foreach ($orderedWorkshopIds as $reference) {
            $mod = $this->findMod($reference, $server);

            if ($mod === null) {
                continue;
            }

            $folder = (string) $mod['folder_name'];

            if (!in_array($folder, $order, true)) {
                $order[] = $folder;
            }
        }

        // Enabled mods the caller did not mention keep their relative order at
        // the end of the list, so a partial reorder never disables anything.
        foreach ($enabled as $folder) {
            if (!in_array($folder, $order, true)) {
                $order[] = $folder;
            }
        }

        return $this->persistOrder($server, $order, 'reorder') + ['ordered_ids' => $orderedWorkshopIds];
    }

    /**
     * Writes a load order back to Pterodactyl and reports the outcome.
     *
     * @param list<string> $order
     * @return array<string, mixed>
     */
    private function persistOrder(mixed $server, array $order, string $action): array
    {
        $result = $this->startup->saveModList($server, $order);
        $this->memo = [];

        return [
            'status'            => $result['saved'] ? 'applied' : 'failed',
            'action'            => $action,
            'target'            => $result['target'],
            'message'           => $result['message'] . ($result['saved'] ? ' Restart the server to apply it.' : ''),
            'load_order'        => $order,
            'launch_parameters' => (new LaunchParameterBuilder())->build($order),
        ];
    }

    /**
     * Finds an installed mod by workshop ID or folder name.
     *
     * @return array<string, mixed>|null
     */
    private function findMod(string $reference, mixed $server): ?array
    {
        $reference = ltrim(trim($reference), '@');

        if ($reference === '') {
            return null;
        }

        foreach ($this->installedMods($server) as $mod) {
            $folder = ltrim((string) ($mod['folder_name'] ?? ''), '@');

            if ((string) ($mod['workshop_id'] ?? '') === $reference || strcasecmp($folder, $reference) === 0) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * Mod folders present in the server root.
     *
     * @return list<array{name: string, size: int}>
     */
    private function modFolders(mixed $server): array
    {
        $folders = [];

        foreach ($this->gateway->listDirectory($server, '/') as $entry) {
            $name = $entry['name'];

            if ($name === '' || !str_starts_with($name, '@') || $entry['file']) {
                continue;
            }

            $folders[] = ['name' => $name, 'size' => $entry['size']];
        }

        usort($folders, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $folders;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeMod(
        mixed $server,
        string $folder,
        bool $installed,
        int $size,
        bool $enabled,
        bool $serverOnly,
        int $position,
    ): array {
        $meta = $installed ? $this->modMetadata($server, $folder) : [];
        $workshopId = $meta['publishedid'] ?? '';

        return [
            'workshop_id'     => $workshopId,
            'title'           => $meta['name'] ?? ltrim($folder, '@'),
            'folder_name'     => $folder,
            'author'          => $meta['author'] ?? '',
            'thumbnail'       => '',
            'current_version' => $meta['version'] ?? '',
            'latest_version'  => '',
            'file_size'       => $installed ? $this->formatBytes($size) : '',
            'enabled'         => $enabled,
            'installed'       => $installed,
            'server_only'     => $serverOnly,
            'position'        => $position,
            'workshop_url'    => $workshopId === ''
                ? ''
                : 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . rawurlencode($workshopId),
            'dependencies'    => [],
        ];
    }

    /**
     * Reads `meta.cpp` and `mod.cpp` from a mod folder.
     *
     * @return array<string, string>
     */
    private function modMetadata(mixed $server, string $folder): array
    {
        $metadata = [];

        // meta.cpp is read first and owns the Workshop ID; mod.cpp then adds the
        // author and version without overwriting what meta.cpp provided.
        foreach (['meta.cpp', 'mod.cpp'] as $file) {
            $contents = $this->gateway->readFile($server, '/' . $folder . '/' . $file);

            if ($contents === null || $contents === '') {
                continue;
            }

            $metadata += $this->meta->parse($contents);
        }

        return $metadata;
    }

    /**
     * Mods recorded in the panel database, used when the daemon is unreachable.
     *
     * @return list<array<string, mixed>>
     */
    private function databaseMods(): array
    {
        try {
            if (!class_exists('Illuminate\\Support\\Facades\\Schema')
                || !class_exists('Illuminate\\Support\\Facades\\DB')
                || !\Illuminate\Support\Facades\Schema::hasTable('dayz_mods')) {
                return [];
            }

            $rows = \Illuminate\Support\Facades\DB::table('dayz_mods')
                ->orderBy('position')
                ->get()
                ->toArray();
        } catch (Throwable) {
            return [];
        }

        return array_map(function ($row): array {
            $row = (array) $row;
            $workshopId = (string) ($row['workshop_id'] ?? '');
            $dependencies = (string) ($row['dependencies'] ?? '');

            return [
                'workshop_id'     => $workshopId,
                'title'           => (string) ($row['title'] ?? ''),
                'folder_name'     => (string) ($row['folder_name'] ?? ''),
                'author'          => '',
                'thumbnail'       => '',
                'current_version' => (string) ($row['version'] ?? ''),
                'latest_version'  => (string) ($row['latest_version'] ?? ''),
                'file_size'       => '',
                'enabled'         => (bool) ($row['enabled'] ?? false),
                'installed'       => true,
                'server_only'     => false,
                'position'        => (int) ($row['position'] ?? 0),
                'workshop_url'    => $workshopId === ''
                    ? ''
                    : 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . rawurlencode($workshopId),
                'dependencies'    => $dependencies === '' ? [] : array_values(array_filter(explode(',', $dependencies))),
            ];
        }, $rows);
    }

    private function resolveServer(mixed $server): mixed
    {
        if (is_object($server)) {
            return $server;
        }

        return $this->context->resolve($server)['model'];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log((float) $bytes, 1024)), count($units) - 1);

        return sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
    }
}
