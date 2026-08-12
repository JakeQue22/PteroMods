<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\LaunchParameterBuilder;
use PteroMods\Services\DayZ\ModMetaParser;
use PteroMods\Services\DayZ\WorkshopBrowseClient;
use PteroMods\Services\DayZ\WorkshopDependencyPlanner;
use PteroMods\Services\DayZ\WorkshopInfoClient;
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
    private const STATS_CACHE_SECONDS = 120;

    /** How long a fetched Workshop item description is cached for. */
    private const INFO_CACHE_SECONDS = 3600;

    /** @var array<string, list<array<string, mixed>>> Per-request mod cache. */
    private array $memo = [];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStartupService $startup = new DayZStartupService(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZConfigurationService $configuration = new DayZConfigurationService(),
        private readonly ModMetaParser $meta = new ModMetaParser(),
        private readonly WorkshopInfoClient $info = new WorkshopInfoClient(),
        private readonly WorkshopBrowseClient $browseClient = new WorkshopBrowseClient(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
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
        $stats = $this->cachedWorkshopStats($server);
        $startup = $stats['startup'];
        $installed = $stats['installed'];
        $enabled = $stats['enabled'];

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

        return $this->memo[$memoKey] = $this->cachedInstalledMods($server, $memoKey);
    }

    /**
     * Stale-while-revalidate cache around the expensive mod scan (file
     * listing + per-folder meta.cpp reads + per-mod Workshop API lookups),
     * whose cost grows with the number of installed mods. Without this, the
     * Workshop Mods page recomputed the full scan from scratch on every
     * request, which made `/dayz/mods` progressively slower to load the more
     * mods were active. `settings()` already benefited from this caching via
     * `cachedWorkshopStats()`, but `installedMods()` itself (used directly by
     * the mods page and dashboard) did not, so the expensive work still ran
     * on every request that only needed the mod list.
     *
     * @return list<array<string, mixed>>
     */
    private function cachedInstalledMods(mixed $server, string $memoKey): array
    {
        $key = 'pteromods.dayz.workshop.installed_mods.' . md5($memoKey);
        $mods = $this->staleCache->remember(
            $key,
            self::STATS_CACHE_SECONDS,
            self::STATS_CACHE_SECONDS * 20,
            fn (): array => $this->scanInstalledMods($server),
            [],
            // Never replace a non-empty mod list with an empty one.  A transient
            // Wings API error, a race during container startup, or any other
            // reason the scan returns 0 mods must not wipe out the last known
            // good list.  Stale mod data is always preferable to showing 0 mods.
            static fn (mixed $new, mixed $old): bool =>
                !is_array($old) || count($old) === 0
                || (is_array($new) && count($new) > 0),
        );

        return is_array($mods) ? $mods : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanInstalledMods(mixed $server): array
    {
        $startup = $this->startup->startup($server);
        $loadOrder = $startup['mods'];
        $serverMods = $startup['server_mods'];

        $folders = $this->modFolders($server);

        if ($folders === [] && $loadOrder === [] && $serverMods === []) {
            return $this->databaseMods();
        }

        // Proactively rename any folder still using its numeric Workshop ID
        // (e.g. `@1559212036`) to the mod's friendly name and drop that ID
        // from the SteamCMD download variable. Previously this only happened
        // while actively polling a fresh install (syncInstalledTypesExtra()),
        // so a mod installed/updated through any other path (server restart,
        // externally edited modlist.html, etc.) kept its numeric folder name
        // forever, which made every subsequent scan treat it as "not
        // installed" and re-queue/re-download it into a duplicate @ID folder.
        //
        // Gated behind the `rename_mods_to_friendly_names` setting (default
        // off): the DayZ egg's container startup script only recognises a
        // mod as installed by checking for a folder literally named
        // `@<numericWorkshopId>`. Renaming that folder away from the numeric
        // ID makes the egg's own check fail and re-download the mod into a
        // fresh duplicate `@<id>` folder on the next restart, which is the
        // exact bug this setting avoids by leaving folders untouched.
        if ($this->settings->get('rename_mods_to_friendly_names', false)
            && $this->renameNumericModFolders($server, $folders)) {
            $this->gateway->clearFileListingCache($server);
            $startup = $this->startup->startup($server);
            $loadOrder = $startup['mods'];
            $serverMods = $startup['server_mods'];
            $folders = $this->modFolders($server);
        }

        $known = [];

        foreach ($folders as $folder) {
            $folderKey = strtolower($folder['name']);
            $known[$folderKey] = $folder;

            // Also index by the "other" form (bare numeric <-> @-prefixed) so a
            // folder on disk always matches regardless of which form the
            // startup command's load order happens to reference it by. This
            // is what makes a freshly-installed mod's tile resolve to its
            // real `@<id>` folder instead of showing as "files missing":
            // appendToEnabledLoadOrder() (further below) records the bare
            // numeric Workshop ID in the load order, while the folder on disk
            // is always named `@<id>` (or renamed to a friendly name).
            $bareKey = ltrim($folderKey, '@');

            if (ctype_digit($bareKey)) {
                $known[$bareKey] ??= $folder;
                $known['@' . $bareKey] ??= $folder;
            }
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

        // Expand enabledKeys / serverOnlyKeys to cover both the bare numeric form
        // ('1797720064') and the @-prefixed folder form ('@1797720064') so that mods
        // referenced as numeric IDs in the startup command are still shown as enabled.
        foreach ([$loadOrder, $serverMods] as $i => $list) {
            foreach ($list as $f) {
                $lower = strtolower($f);
                $bare = ltrim($lower, '@');
                if (ctype_digit($bare)) {
                    if ($i === 0) {
                        $enabledKeys[] = '@' . $bare;
                        $enabledKeys[] = $bare;
                    } else {
                        $serverOnlyKeys[] = '@' . $bare;
                        $serverOnlyKeys[] = $bare;
                    }
                }
            }
        }

        $enabledKeys = array_unique($enabledKeys);
        $serverOnlyKeys = array_unique($serverOnlyKeys);

        // When the startup is available but the load order is empty, all mods
        // should show as disabled (not enabled). The "all installed = enabled"
        // fallback only applies when the daemon is unreachable and no startup
        // data exists at all.
        $startupKnown = $startup['source'] !== 'unavailable';

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
                enabled: ($enabledKeys === [] && !$startupKnown) ? $entry !== null : in_array($key, $enabledKeys, true),
                serverOnly: in_array($key, $serverOnlyKeys, true),
                position: $position,
            );

            $position++;
        }

        // Merge numeric "not-installed, enabled" placeholder entries with their
        // installed counterparts. When the startup command references a mod by its
        // numeric Workshop ID (e.g. 1797720064) and the corresponding folder on disk
        // has been renamed to its proper name (e.g. @CF), the two entries would
        // otherwise appear as separate rows. This pass promotes the installed entry
        // to "enabled" and removes the numeric placeholder.
        $widsToInstalledIdx = [];

        foreach ($mods as $idx => $mod) {
            $wid = (string) ($mod['workshop_id'] ?? '');

            if ($wid !== '' && ($mod['installed'] ?? false)) {
                $widsToInstalledIdx[$wid] = $idx;
            }
        }

        foreach ($mods as $idx => $mod) {
            $folder = ltrim((string) ($mod['folder_name'] ?? ''), '@');

            if (!ctype_digit($folder) || ($mod['installed'] ?? false) || !($mod['enabled'] ?? false)) {
                continue;
            }

            // Numeric folder = Workshop ID; check if an installed mod has this Workshop ID.
            if (isset($widsToInstalledIdx[$folder])) {
                $mods[$widsToInstalledIdx[$folder]]['enabled'] = true;
                unset($mods[$idx]);
            }
        }

        $mods = array_values($mods);

        // Deduplicate by Workshop ID: when the same mod appears under both its
        // mod-name folder (@ModName) and its numeric Workshop ID folder (@1797720064),
        // keep only one entry, preferring the installed non-numeric-folder version.
        $seenIds = [];
        $deduped = [];

        foreach ($mods as $mod) {
            $wid = (string) ($mod['workshop_id'] ?? '');
            $folder = ltrim((string) ($mod['folder_name'] ?? ''), '@');

            if ($wid !== '') {
                if (!isset($seenIds[$wid])) {
                    $seenIds[$wid] = count($deduped);
                    $deduped[] = $mod;
                } else {
                    $existingIdx = $seenIds[$wid];
                    $existingFolder = ltrim((string) ($deduped[$existingIdx]['folder_name'] ?? ''), '@');

                    // Replace the existing entry when this one is installed and the
                    // existing one uses a raw numeric folder name while this one does not.
                    if (($mod['installed'] ?? false)
                        && ctype_digit($existingFolder)
                        && !ctype_digit($folder)) {
                        $deduped[$existingIdx] = $mod;
                    }
                }
            } else {
                // No Workshop ID yet (not installed): skip if a bare numeric folder
                // duplicates a mod already represented by its Workshop ID.
                if (ctype_digit($folder) && isset($seenIds[$folder])) {
                    continue;
                }

                $deduped[] = $mod;
            }
        }

        // Re-index positions after deduplication.
        $mods = array_values($deduped);

        // The Community Framework must always be shown (and load) first: many
        // scripted mods depend on it, and loading it after them crashes the
        // server. This mirrors the same enforcement applied to the persisted
        // load order in enforceCommunityFrameworkFirst(), so the displayed
        // "Load position" always matches what will actually happen on boot.
        $cfIndex = null;

        foreach ($mods as $idx => $mod) {
            if ((string) ($mod['workshop_id'] ?? '') === WorkshopDependencyPlanner::COMMUNITY_FRAMEWORK_ID) {
                $cfIndex = $idx;
                break;
            }
        }

        if ($cfIndex !== null && $cfIndex > 0) {
            $cfMod = $mods[$cfIndex];
            unset($mods[$cfIndex]);
            array_unshift($mods, $cfMod);
            $mods = array_values($mods);
        }

        foreach ($mods as $idx => $_) {
            $mods[$idx]['position'] = $idx;
        }

        return $mods;
    }

    /**
     * Live preview of a Workshop reference (ID or URL) before it is queued:
     * the title and thumbnail, so the search box can show what an ID
     * actually is as the operator types it.
     *
     * @return array<string, mixed>
     */
    public function lookup(string $reference, mixed $server = null): array
    {
        try {
            $workshopId = (new WorkshopReferenceParser())->parse($reference);
        } catch (Throwable) {
            return ['workshop_id' => '', 'title' => '', 'thumbnail' => '', 'file_size' => '', 'found' => false, 'installed' => false];
        }

        $info = $this->workshopInfo($workshopId);
        $installed = $server !== null && $this->findMod($workshopId, $this->resolveServer($server)) !== null;

        return [
            'workshop_id' => $workshopId,
            'title'       => $info['title'] ?? '',
            'thumbnail'   => $info['thumbnail'] ?? '',
            'file_size'   => $info['file_size'] ?? '',
            'found'       => $info !== [] && ($info['title'] ?? '') !== '',
            'installed'   => $installed,
        ];
    }

    /**
     * Whether the Steam Web API key needed for Workshop browsing is configured.
     */
    public function isBrowseEnabled(): bool
    {
        return $this->steamApiKey() !== '';
    }

    /**
     * Browses the DayZ Workshop by search term (or the current trending
     * items when the term is blank), so mods can be discovered by name.
     *
     * @return array<string, mixed>
     */
    public function browse(string $term = '', int $page = 1, mixed $server = null, array $options = []): array
    {
        $apiKey = $this->steamApiKey();

        if ($apiKey === '') {
            return [
                'items' => [],
                'page' => $page,
                'has_more' => false,
                'per_page' => 24,
                'sort' => 'most_popular',
                'filters' => ['type' => '', 'mod_type' => '', 'required_dlc' => ''],
                'available_sorts' => $this->browseClient->availableSorts(),
                'available_filters' => $this->browseClient->availableFilters(),
                'enabled' => false,
                'message' => 'Set the STEAM_WEB_API_KEY environment variable (a free Steam Web API key) '
                    . 'on the panel to enable browsing the Workshop.',
            ];
        }

        $payload = $this->browseClient->search($term, $page, $apiKey, $options);

        if ($server === null || !is_array($payload['items'] ?? null)) {
            return $payload + ['enabled' => true, 'message' => ''];
        }

        $installedIds = $this->installedWorkshopIds($this->resolveServer($server));

        $payload['items'] = array_map(static function (array $item) use ($installedIds): array {
            $id = trim((string) ($item['workshop_id'] ?? ''));
            $item['installed'] = $id !== '' && in_array($id, $installedIds, true);

            return $item;
        }, $payload['items']);

        return $payload + ['enabled' => true, 'message' => ''];
    }

    /**
     * The Steam Web API key used for Workshop browsing, if one is configured.
     */
    private function steamApiKey(): string
    {
        // Check the panel-level DB setting first (set via the DayZ Manager settings page).
        try {
            $dbKey = (new DayZManagerSettingsService())->get('steam_web_api_key', '');

            if (is_string($dbKey) && $dbKey !== '') {
                return $dbKey;
            }
        } catch (\Throwable) {
            // Settings service unavailable; fall through to environment variables.
        }

        foreach (['STEAM_WEB_API_KEY', 'PTEROMODS_STEAM_API_KEY'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (function_exists('config')) {
            try {
                $value = config('services.steam.key');

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            } catch (Throwable) {
                // Config may be unavailable outside a booted Laravel app.
            }
        }

        return '';
    }

    /**
     * Builds an install plan for a Workshop reference: the dependency-ordered
     * download queue, and the public details (title, thumbnail, size) for
     * every item in it, so the operator sees what each ID actually is right
     * away instead of a bare number.
     *
     * @param array<string, array{dependencies?: list<string>, requires_cf?: bool}> $metadata
     * @return array<string, mixed>
     */
    public function installPlan(string $reference, array $metadata = [], mixed $server = null, bool $forceRestart = false): array
    {
        $workshopId = (new WorkshopReferenceParser())->parse($reference);
        $plan = (new WorkshopDependencyPlanner())->buildPlan($workshopId, $metadata + [
            $workshopId => $metadata[$workshopId] ?? ['dependencies' => [], 'requires_cf' => false],
        ]);

        $server = $this->resolveServer($server);
        $installedIds = $this->installedWorkshopIds($server);
        $persistedQueue = $this->persistedQueue($server);
        $queuedIds = array_values(array_filter(array_map(
            static fn (array $entry): string => trim((string) ($entry['workshop_id'] ?? '')),
            $persistedQueue,
        ), static fn (string $id): bool => $id !== ''));
        $skipIds = array_values(array_unique(array_merge($installedIds, $queuedIds)));

        // Also recognize mods that are already present on disk under their
        // friendly `@ModName` folder rather than the numeric `@workshopId`
        // folder. `installedWorkshopIds()` only matches on the `publishedid`
        // recorded in meta.cpp/mod.cpp, which is not always reliable (some
        // mods ship without it, or it was stripped by a prior manual install),
        // so every requested/dependency Workshop ID is additionally checked
        // against the folder name its title would produce. This is the same
        // scheme `maybeRenameFolderToModName()` uses, so a match here means
        // SteamCMD would otherwise re-download a mod the server already has.
        $installedFolderKeys = $this->installedFolderKeys($server);
        $plan = array_values(array_filter($plan, function (string $id) use ($skipIds, $installedFolderKeys, $metadata): bool {
            if (in_array($id, $skipIds, true)) {
                return false;
            }

            if ($installedFolderKeys === []) {
                return true;
            }

            $title = trim((string) ($metadata[$id]['title'] ?? ($this->workshopInfo($id)['title'] ?? '')));

            if ($title === '') {
                return true;
            }

            $folderKey = strtolower(ltrim($this->modFolderNameFromTitle($title, $id), '@'));

            return !in_array($folderKey, $installedFolderKeys, true);
        }));

        if ($plan === []) {
            return [
                'workshop_id' => $workshopId,
                'title' => '',
                'thumbnail' => '',
                'file_size' => '',
                'install_order' => [],
                'queue' => $persistedQueue,
                'restart_triggered' => false,
                'restart_after_update' => false,
                'restart_required' => false,
                'auto_dependency_installation' => true,
                'status' => 'noop',
                'message' => 'That mod and all required dependencies are already installed or queued.',
            ];
        }

        $queue = array_merge(
            array_values(array_filter(array_map(static function (array $entry): ?array {
                $id = trim((string) ($entry['workshop_id'] ?? ''));

                return $id === '' ? null : [
                    'workshop_id' => $id,
                    'title' => (string) ($entry['title'] ?? ''),
                    'thumbnail' => (string) ($entry['thumbnail'] ?? ''),
                    'file_size' => (string) ($entry['file_size'] ?? ''),
                    'installed' => false,
                    'status' => 'queued',
                ];
            }, $persistedQueue))),
            array_map(fn (string $id): array => $this->queueEntry($id, $server), $plan),
        );
        $item = current(array_filter($queue, static fn (array $entry): bool => $entry['workshop_id'] === $workshopId)) ?: null;

        $this->persistQueue($server, $queue);
        $this->gateway->clearFileListingCache($server);
        $this->invalidateModsCache($server);

        // Append the new workshop IDs to the egg's MODS variable so the
        // startup script can pass them to SteamCMD on the next boot.
        // NOTE: Do NOT add the IDs to the enabled load order here – the mod
        // files have not been downloaded yet, so adding them to -mod= would
        // create a "missing files" placeholder in the installed mods list
        // before the server ever boots. The load order is updated by
        // syncInstalledTypesExtra() once the mod is detected on disk.
        $this->startup->appendWorkshopIds($server, $plan);

        // appendWorkshopIds() only writes modlist.html with the *newly*
        // queued IDs when it falls back to its own internal sync (no egg
        // variable matched), which would overwrite modlist.html and drop
        // every already-installed/queued mod from it. Explicitly rewrite
        // modlist.html here with the full accumulated set (installed +
        // queued + newly-planned) so the file the server/egg reads for mod
        // downloads always reflects everything currently queued, not just
        // this single request's IDs.
        //
        // This write is the *only* mechanism that actually reaches SteamCMD
        // for eggs whose mod variable (e.g. `MODIFICATIONS`) is not one of
        // DayZStartupService::MOD_LIST_VARIABLES, so a silent failure here
        // (Wings unreachable, container not yet created, etc.) means the
        // Workshop ID never gets communicated to the startup script at all —
        // the mod would then sit in the queue forever, since nothing ever
        // asked SteamCMD to fetch it. Surface that failure in the response
        // instead of swallowing it, so the operator knows to retry.
        $modlistSynced = $this->startup->syncModlistHtml($server, $this->modlistWorkshopIds($server, $plan));

        // Restart only when explicitly requested by the operator.
        $restarted = $forceRestart ? $this->gateway->power($server, 'restart') : false;

        $message = $restarted
            ? 'Install queued. The server is restarting so its startup script can download queued mods via SteamCMD.'
            : 'Install queued. Restart the server when you are ready to download queued mods via SteamCMD.';

        if (!$modlistSynced) {
            $message = 'Install queued, but the modlist could not be updated on the server (the node may be offline). '
                . 'The mod will stay in the queue until you retry the install so SteamCMD is actually told to download it.';
        }

        return [
            'workshop_id' => $workshopId,
            'title' => $item['title'] ?? '',
            'thumbnail' => $item['thumbnail'] ?? '',
            'file_size' => $item['file_size'] ?? '',
            'install_order' => $plan,
            'queue' => $queue,
            'restart_triggered' => $restarted,
            'restart_after_update' => $restarted,
            'restart_required' => true,
            'auto_dependency_installation' => true,
            'status' => $modlistSynced ? 'queued' : 'failed',
            'message' => $message,
        ];
    }

    /**
     * Reports the download/installation progress of a Workshop install plan,
     * so the page can poll it after `installPlan()` queued the download.
     *
     * When no Workshop IDs are supplied, every mod still queued for this
     * server (per the persisted install queue) is reported instead, so a
     * page reload can resume watching an install that was already in
     * progress rather than losing track of it.
     *
     * @param list<string> $workshopIds
     * @return array<string, mixed>
     */
    public function installStatus(array $workshopIds, mixed $server = null): array
    {
        $server = $this->resolveServer($server);
        $persistedQueue = $this->persistedQueue($server);
        $persistedIds = array_values(array_filter(array_map(
            static fn (array $entry): string => trim((string) ($entry['workshop_id'] ?? '')),
            $persistedQueue,
        ), static fn (string $id): bool => $id !== ''));

        // Always fold in the full persisted queue rather than restricting to
        // just the IDs the caller happened to pass in. A poll started for one
        // freshly-queued mod (e.g. via startInstallPolling() after a single
        // install request) previously only checked/persisted that one mod's
        // status, and persistQueue() then deleted every other still-queued
        // mod from `dayz_mod_install_queue` because they were not in that
        // narrower list. That made the queue UI collapse down to whichever
        // mod was queued (or polled) most recently instead of showing every
        // mod still waiting to install.
        $workshopIds = array_values(array_unique(array_merge($persistedIds, $workshopIds)));

        // Clear the file-listing cache so the status check always reads the
        // latest files from Wings rather than a 30-second-old snapshot.
        $this->gateway->clearFileListingCache($server);
        $this->invalidateModsCache($server);

        $statusQueue = array_map(fn (string $id): array => $this->queueEntry($id, $server), $workshopIds);
        $complete = $statusQueue !== [] && !in_array(false, array_column($statusQueue, 'installed'), true);
        $installedIds = array_values(array_filter(array_map(
            static fn (array $entry): string => (bool) ($entry['installed'] ?? false) ? trim((string) ($entry['workshop_id'] ?? '')) : '',
            $statusQueue,
        ), static fn (string $id): bool => $id !== ''));
        $queue = array_values(array_filter($statusQueue, static fn (array $entry): bool => !($entry['installed'] ?? false)));

        $this->persistQueue($server, $queue);

        if ($installedIds !== []) {
            $this->startup->removeWorkshopIds($server, $installedIds, $this->modlistWorkshopIds($server));
        }

        $this->syncInstalledTypesExtra($server, $workshopIds);

        if ($installedIds !== []) {
            // Now that the mods are on disk (and folders may have been renamed by
            // syncInstalledTypesExtra above), add them to the enabled load order so
            // they become active on the next server boot without the operator needing
            // to manually enable each one. This must happen after queue/startup
            // cleanup so the order reflects the final on-disk state.
            $this->appendToEnabledLoadOrder($server, $installedIds);

            // The mods are now downloaded and enabled in the load order, but the
            // DayZ egg's startup script only actually launches with them after a
            // restart that happens *after* this point. Mark the server as
            // needing a follow-up restart (and, once running again, a DZSA
            // Launcher submission) so DayZServerService::tickModInstallFollowUp()
            // can act on it without the operator needing to notice manually.
            $this->markPendingModInstallFollowUp($server);
        }

        return [
            'queue' => $queue,
            'complete' => $complete || $queue === [],
        ];
    }

    /**
     * Records that this server has newly-installed mods waiting for a
     * follow-up restart (and DZSA submission) so
     * DayZServerService::tickModInstallFollowUp() can process it later.
     */
    private function markPendingModInstallFollowUp(mixed $server): void
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_dzsa_pending')) {
                return;
            }

            $exists = \Illuminate\Support\Facades\DB::table('dayz_dzsa_pending')
                ->where('server_id', $serverId)
                ->exists();

            \Illuminate\Support\Facades\DB::table('dayz_dzsa_pending')->updateOrInsert(
                ['server_id' => $serverId],
                array_merge(
                    ['status' => 'waiting', 'updated_at' => date('Y-m-d H:i:s')],
                    $exists ? [] : ['created_at' => date('Y-m-d H:i:s')],
                ),
            );
        } catch (Throwable) {
            // Best-effort bookkeeping; missing this only means the operator
            // needs to notice and restart/refresh DZSA manually.
        }
    }

    /**
     * The persisted install queue for a server, refreshed against the mods
     * actually found on disk. Used to restore the `/mods` page's "downloading…"
     * banner after a reload, instead of it disappearing because no in-memory
     * state survived the request.
     *
     * @return array<string, mixed>
     */
    public function queue(mixed $server = null): array
    {
        return $this->installStatus([], $server);
    }

    /**
     * Public Workshop details plus on-server install state for one item.
     *
     * @return array<string, mixed>
     */
    private function queueEntry(string $workshopId, mixed $server): array
    {
        $mod = $server === null ? null : $this->findMod($workshopId, $server);
        $info = $this->workshopInfo($workshopId);

        // Prefer the Steam API title over the mod's title when the mod title is
        // just the numeric folder name (e.g. "1559212036"), which is a fallback
        // and would cause the queue to display "ID (ID)" instead of "Name (ID)".
        $modTitle = (string) ($mod['title'] ?? '');
        $apiTitle = (string) ($info['title'] ?? '');
        $title = ($apiTitle !== '') ? $apiTitle : ($modTitle !== $workshopId ? $modTitle : '');

        return [
            'workshop_id' => $workshopId,
            'title'       => $title,
            'thumbnail'   => $info['thumbnail'] ?? '',
            'file_size'   => $info['file_size'] ?? 0,
            'installed'   => $mod !== null && ($mod['installed'] ?? false),
            'status'      => $mod !== null && ($mod['installed'] ?? false) ? 'installed' : 'downloading',
        ];
    }

    /**
     * Writes the install queue to `dayz_mod_install_queue`, so it survives a
     * page refresh and a fresh request can rebuild the same "downloading…"
     * banner instead of losing all progress state.
     *
     * @param list<array<string, mixed>> $queue
     */
    private function persistQueue(mixed $server, array $queue): void
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_mod_install_queue')) {
                return;
            }

            $workshopIds = array_values(array_filter(array_map(
                static fn (array $entry): string => (string) ($entry['workshop_id'] ?? ''),
                $queue,
            ), static fn (string $id): bool => $id !== ''));
            $query = \Illuminate\Support\Facades\DB::table('dayz_mod_install_queue')->where('server_id', $serverId);

            if ($workshopIds === []) {
                $query->delete();
            } else {
                $query->whereNotIn('workshop_id', $workshopIds)->delete();
            }

            foreach (array_values($queue) as $position => $entry) {
                $workshopId = (string) ($entry['workshop_id'] ?? '');

                if ($workshopId === '') {
                    continue;
                }

                \Illuminate\Support\Facades\DB::table('dayz_mod_install_queue')->updateOrInsert(
                    ['server_id' => $serverId, 'workshop_id' => $workshopId],
                    [
                        'title'      => (string) ($entry['title'] ?? ''),
                        'thumbnail'  => (string) ($entry['thumbnail'] ?? ''),
                        'file_size'  => (string) ($entry['file_size'] ?? ''),
                        'status'     => (string) ($entry['status'] ?? 'queued'),
                        'position'   => $position,
                        'updated_at' => date('Y-m-d H:i:s'),
                        'created_at' => date('Y-m-d H:i:s'),
                    ],
                );
            }
        } catch (Throwable) {
            // Persisting the queue is best-effort; the live folder scan is
            // still authoritative for whether a mod is actually installed.
        }
    }

    /**
     * @param list<string> $workshopIds
     */
    private function deleteQueueEntries(mixed $server, array $workshopIds): bool
    {
        $serverId = $this->serverIdentifier($server);
        $workshopIds = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $workshopIds,
        ), static fn (string $id): bool => $id !== ''));

        if ($serverId === ''
            || $workshopIds === []
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return false;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_mod_install_queue')) {
                return false;
            }

            $deleted = \Illuminate\Support\Facades\DB::table('dayz_mod_install_queue')
                ->where('server_id', $serverId)
                ->whereIn('workshop_id', $workshopIds)
                ->delete();
        } catch (Throwable) {
            return false;
        }

        return $deleted > 0;
    }

    /**
     * Rows persisted for this server, oldest queued position first.
     *
     * @return list<array<string, mixed>>
     */
    private function persistedQueue(mixed $server): array
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return [];
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_mod_install_queue')) {
                return [];
            }

            return \Illuminate\Support\Facades\DB::table('dayz_mod_install_queue')
                ->where('server_id', $serverId)
                ->orderBy('position')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function serverIdentifier(mixed $server): string
    {
        return $server === null ? '' : $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
    }

    /**
     * Clears both the per-request memo and the persistent stale-while-
     * revalidate cache for a server's installed-mods list, so a mutation
     * (install/remove/enable/disable/rename) is reflected immediately
     * instead of waiting up to STATS_CACHE_SECONDS for the cache to expire.
     */
    private function invalidateModsCache(mixed $server): void
    {
        $this->memo = [];

        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        $memoKey = $this->serverIdentifier($server);

        if ($memoKey === '') {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::forget('pteromods.dayz.workshop.installed_mods.' . md5($memoKey));
            \Illuminate\Support\Facades\Cache::forget('pteromods.dayz.workshop.stats.' . md5($memoKey));
        } catch (Throwable) {
            // Best-effort invalidation only.
        }
    }

    /**
     * Ensures newly queued Workshop IDs are present in the `-mod=` load order
     * so they stay enabled after install unless explicitly disabled.
     *
     * @param list<string> $workshopIds
     */
    private function appendToEnabledLoadOrder(mixed $server, array $workshopIds): void
    {
        $workshopIds = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $workshopIds,
        ), static fn (string $id): bool => ctype_digit($id)));

        if ($workshopIds === []) {
            return;
        }

        $order = $this->enabledFolders($server);
        $resolvedFolders = [];

        foreach ($this->installedMods($server) as $mod) {
            $id = trim((string) ($mod['workshop_id'] ?? ''));
            $folder = trim((string) ($mod['folder_name'] ?? ''));

            if ($id !== '' && $folder !== '' && in_array($id, $workshopIds, true)) {
                $resolvedFolders[$id] = $folder;
            }
        }

        $changed = false;

        foreach ($workshopIds as $workshopId) {
            $folder = $resolvedFolders[$workshopId] ?? ('@' . $workshopId);
            $updated = $this->replaceLoadOrderReferences($order, $workshopId, $folder);

            if ($updated !== $order) {
                $order = $updated;
                $changed = true;
            }

            $exists = false;

            foreach ($order as $entry) {
                if (strcasecmp($entry, $folder) === 0 || ltrim(strtolower($entry), '@') === strtolower($workshopId)) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $order[] = $folder;
                $changed = true;
            }
        }

        if ($changed) {
            $this->startup->saveModList($server, $order);
            $this->invalidateModsCache($server);
        }
    }

    /**
     * Public Workshop item details, cached because the Steam API is called
     * over the network and the same ID is looked up repeatedly while a
     * download is in progress.
     *
     * @return array<string, mixed>
     */
    private function workshopInfo(string $workshopId): array
    {
        $key = 'pteromods.dayz.workshop_info.' . $workshopId;
        $info = $this->staleCache->remember(
            $key,
            self::INFO_CACHE_SECONDS,
            self::INFO_CACHE_SECONDS * 20,
            fn (): array => $this->info->fetch($workshopId) ?? [],
            [],
        );

        return is_array($info) ? $info : [];
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
        $dependencyAction = strtolower(trim((string) $this->context->input('dependency_action', '')));

        if ($mod === null) {
            return $this->removeQueued($reference, $server);
        }

        $dependents = $this->dependentMods((string) ($mod['workshop_id'] ?? ''), $server);

        if ($dependents !== [] && $dependencyAction === '') {
            return [
                'status' => 'dependency_prompt',
                'action' => 'remove',
                'workshop_id' => (string) ($mod['workshop_id'] ?? ''),
                'dependents' => $dependents,
                'message' => 'Other installed mods depend on this mod. Choose remove one or remove all.',
            ];
        }

        if ($dependents !== [] && !in_array($dependencyAction, ['remove_single', 'remove_all'], true)) {
            return ['status' => 'failed', 'action' => 'remove', 'message' => 'Removal cancelled.'];
        }

        if ($dependencyAction === 'remove_all') {
            foreach ($dependents as $dependent) {
                $dependentReference = (string) ($dependent['workshop_id'] ?? '');

                if ($dependentReference !== '') {
                    $this->remove($dependentReference, $server, $deleteFiles);
                }
            }
        }

        $folder = (string) $mod['folder_name'];
        $workshopId = trim((string) ($mod['workshop_id'] ?? ''));
        $title = (string) ($mod['title'] ?? '');
        $result = $this->toggle($reference, false, $server);
        $filesDeleted = false;

        if ($deleteFiles && ($mod['installed'] ?? false)) {
            $filesDeleted = $this->gateway->deletePath($server, '/' . $folder);
            $this->invalidateModsCache($server);
        }

        // Remove the mod from the persisted full-order table so re-installing
        // it later starts with a clean slate.
        $this->removeFromFullOrder($server, $folder);

        $this->configuration->removeTypesExtraForMod($server, $folder, $title, $workshopId);

        // Clear the install queue for this mod BEFORE computing remaining IDs so
        // that a pending queue entry does not keep its Workshop ID alive in the
        // download variable or modlist.html after the folder has been removed.
        if ($workshopId !== '') {
            $this->deleteQueueEntries($server, [$workshopId]);
        }

        $remainingWorkshopIds = $this->modlistWorkshopIds($server);
        $this->startup->removeWorkshopIds(
            $server,
            $workshopId !== '' ? [$workshopId] : [],
            $remainingWorkshopIds,
        );

        $result['action'] = 'remove';
        $result['files_deleted'] = $filesDeleted;
        $result['message'] = $filesDeleted
            ? sprintf('Removed %s from the load order and deleted its files.', $folder)
            : sprintf('Removed %s from the load order. Delete the folder in the file manager to free the disk space.', $folder);
        $this->startup->syncModlistHtml($server, $this->modlistWorkshopIds($server));

        return $result;
    }

    /**
     * Removes a Workshop ID from this server's persisted install queue.
     *
     * @return array<string, mixed>
     */
    public function removeQueued(string $reference, mixed $server = null): array
    {
        $server = $this->resolveServer($server);
        $workshopId = trim((string) $reference);

        if (!ctype_digit($workshopId)) {
            $mod = $this->findMod($reference, $server);
            $workshopId = (string) ($mod['workshop_id'] ?? '');
        }

        if ($workshopId === '') {
            return ['status' => 'failed', 'action' => 'queue-remove', 'reference' => $reference, 'message' => 'That queue entry could not be found.'];
        }

        $removed = $this->deleteQueueEntries($server, [$workshopId]);
        $queue = $this->persistedQueue($server);
        $remainingWorkshopIds = $this->modlistWorkshopIds($server);
        $this->startup->removeWorkshopIds($server, [$workshopId], $remainingWorkshopIds);

        return [
            'status' => $removed ? 'applied' : 'failed',
            'action' => 'queue-remove',
            'workshop_id' => $workshopId,
            'queue' => $queue,
            'message' => $removed
                ? 'Removed that Workshop mod from the install queue.'
                : 'That Workshop mod was not in the install queue.',
        ];
    }

    /**
     * Enables or disables a mod by rewriting the `-mod=` load order.
     *
     * When re-enabling a mod the method restores it to its original position
     * in the load order (rather than always appending to the end) by consulting
     * the `dayz_server_mod_order` table, which records the full mod order —
     * including disabled mods — across enable/disable operations.
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
        $workshopId = trim((string) ($mod['workshop_id'] ?? ''));

        // Ensure the full mod order is recorded in the DB before we make any
        // change.  This way a disable never loses a mod's original position.
        $this->bootstrapFullOrder($server);

        $order = $this->enabledFolders($server);

        // Remove entries that reference this mod either by folder name or by its
        // numeric Workshop ID. When the startup command still contains the raw
        // numeric ID (e.g. 1797720064) instead of the renamed folder name
        // (e.g. @CF), both forms must be filtered out so the disable takes effect.
        $order = array_values(array_filter(
            $order,
            static function (string $entry) use ($folder, $workshopId): bool {
                if (strcasecmp($entry, $folder) === 0) {
                    return false;
                }
                if ($workshopId !== '' && ltrim($entry, '@') === $workshopId) {
                    return false;
                }
                return true;
            },
        ));

        if ($enabled) {
            // Insert at the position saved from a previous disable rather than
            // always appending to the end, which would silently reorder mods.
            $insertPos = $this->savedInsertPosition($server, $folder, $order);

            if ($insertPos !== null) {
                array_splice($order, $insertPos, 0, [$folder]);
            } else {
                $order[] = $folder;
            }
        } elseif ($workshopId !== '') {
            // A disabled installed mod must not survive in modlist.html via a
            // stale install-queue entry, because the DayZ egg can re-append
            // that queued Workshop ID to the generated `-mod=` startup value.
            $this->deleteQueueEntries($server, [$workshopId]);
        }

        $result = $this->persistOrder($server, $order, $enabled ? 'enable' : 'disable');

        // Keep the persisted full order in sync with the new enabled state.
        $this->updateFullOrderEnabled($server, $folder, $enabled);

        return $result + ['folder_name' => $folder];
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
     * Moves the Community Framework (CF Tools, Workshop ID
     * {@see WorkshopDependencyPlanner::COMMUNITY_FRAMEWORK_ID}) to the front of
     * an enabled load order, if present.
     *
     * CF must load before any mod that depends on it (nearly every scripted
     * mod does); loading it later crashes the server or breaks dependent
     * mods. Enable/disable/reorder never disturbs the rest of the order, only
     * CF's own position, so a drag-and-drop reorder that moves CF elsewhere
     * is silently corrected back to the front.
     *
     * @param list<string> $order
     * @return list<string>
     */
    private function enforceCommunityFrameworkFirst(mixed $server, array $order): array
    {
        if (count($order) < 2) {
            return $order;
        }

        $cfIndex = null;

        foreach ($order as $index => $folder) {
            $bare = ltrim(strtolower(trim($folder)), '@');

            if ($bare === WorkshopDependencyPlanner::COMMUNITY_FRAMEWORK_ID) {
                $cfIndex = $index;
                break;
            }

            $mod = $this->findMod($folder, $server);

            if ($mod !== null && (string) ($mod['workshop_id'] ?? '') === WorkshopDependencyPlanner::COMMUNITY_FRAMEWORK_ID) {
                $cfIndex = $index;
                break;
            }
        }

        if ($cfIndex === null || $cfIndex === 0) {
            return $order;
        }

        $folder = $order[$cfIndex];
        unset($order[$cfIndex]);
        array_unshift($order, $folder);

        return array_values($order);
    }

    /**
     * Writes a load order back to Pterodactyl and reports the outcome.
     *
     * @param list<string> $order
     * @return array<string, mixed>
     */
    private function persistOrder(mixed $server, array $order, string $action): array
    {
        $order = $this->enforceCommunityFrameworkFirst($server, $order);
        $result = $this->startup->saveModList($server, $order);
        // Clear the memo before reading the updated Workshop ID list so that
        // modlist.html reflects the new enabled state, not the stale cache.
        $this->invalidateModsCache($server);
        $this->startup->syncModlistHtml($server, $this->modlistWorkshopIds($server));

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

            if ($name === '' || !str_starts_with($name, '@') || $entry['file'] || $this->isTransientModFolder($name)) {
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
        $workshopId = $this->resolveWorkshopId($folder, $meta);
        $info = $workshopId !== '' ? $this->workshopInfo($workshopId) : [];
        $workshopBytes = isset($info['file_size']) ? (int) $info['file_size'] : 0;
        $author = trim((string) ($meta['author'] ?? ''));

        if ($author === '' && isset($info['author']) && is_string($info['author'])) {
            $author = trim((string) $info['author']);
        }

        return [
            'workshop_id'     => $workshopId,
            'title'           => $meta['name'] ?? ($info['title'] ?? ltrim($folder, '@')),
            'folder_name'     => $folder,
            'author'          => $author,
            'thumbnail'       => (string) ($info['thumbnail'] ?? ''),
            'current_version' => $meta['version'] ?? '',
            'latest_version'  => '',
            // Wings directory listings report the folder inode size (commonly 4 KB),
            // not the recursive total. Fall back to Steam Workshop's file size.
            'file_size'       => $installed
                ? $this->formatBytes($size > 4096 ? $size : ($workshopBytes > 0 ? $workshopBytes : $size))
                : '',
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
            foreach (['/' . $folder . '/' . $file, '/' . $folder . '/keys/' . $file] as $path) {
                $contents = $this->gateway->readFile($server, $path);

                if ($contents === null || $contents === '') {
                    continue;
                }

                $metadata += $this->meta->parse($contents);
            }
        }

        return $metadata;
    }

    /**
     * @return array{startup: array<string, mixed>, installed: list<array<string, mixed>>, enabled: list<string>}
     */
    private function cachedWorkshopStats(mixed $server): array
    {
        $key = 'pteromods.dayz.workshop.stats.' . md5($this->serverIdentifier($server));
        $fallback = [
            'startup' => ['mods' => [], 'server_mods' => []],
            'installed' => [],
            'enabled' => [],
        ];
        $stats = $this->staleCache->remember(
            $key,
            self::STATS_CACHE_SECONDS,
            self::STATS_CACHE_SECONDS * 20,
            fn (): array => $this->gatherWorkshopStats($server),
            $fallback,
            // Mirror the same guard as cachedInstalledMods: never let a
            // background refresh that returns 0 installed mods overwrite a
            // previously cached list that had mods in it.
            static fn (mixed $new, mixed $old): bool =>
                !is_array($old) || count($old['installed'] ?? []) === 0
                || (is_array($new) && count($new['installed'] ?? []) > 0),
        );

        return is_array($stats) ? $stats : $fallback;
    }

    /**
     * @return array{startup: array<string, mixed>, installed: list<array<string, mixed>>, enabled: list<string>}
     */
    private function gatherWorkshopStats(mixed $server): array
    {
        $installed = $this->installedMods($server);

        return [
            'startup' => $this->startup->startup($server),
            'installed' => $installed,
            'enabled' => array_values(array_filter(array_map(
                static fn (array $mod): string => ($mod['enabled'] ?? false) ? (string) ($mod['folder_name'] ?? '') : '',
                $installed,
            ))),
        ];
    }

    private function resolveWorkshopId(string $folder, array $meta): string
    {
        $publishedId = trim((string) ($meta['publishedid'] ?? ''));

        // Steam uses 0 as the null/unset publishedid (mods installed outside the
        // Workshop, or before the item was published). Treat any non-positive value
        // as unset and fall back to the folder name when it is numeric.
        if ($publishedId !== '' && (int) $publishedId > 0) {
            return $publishedId;
        }

        $bare = ltrim($folder, '@');

        return ctype_digit($bare) ? $bare : '';
    }

    private function isTransientModFolder(string $folder): bool
    {
        $bare = ltrim($folder, '@');

        return str_ends_with(strtolower($bare), '.tmp') || str_ends_with(strtolower($bare), '.temp');
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

    /**
     * @param list<string> $extra
     * @return list<string>
     */
    private function modlistWorkshopIds(mixed $server, array $extra = []): array
    {
        $ids = $extra;
        $disabledInstalledIds = [];

        foreach ($this->installedMods($server) as $mod) {
            $id = trim((string) ($mod['workshop_id'] ?? ''));

            if ($id === '') {
                continue;
            }

            // Always include the Community Framework (CF Tools) when it is
            // installed on the server, regardless of whether it appears in
            // the -mod= load order. Many eggs download it as a dependency
            // without explicitly adding it to the load order, so it would
            // otherwise be silently omitted from modlist.html even though
            // every connected player needs to have it installed.
            if ($id === WorkshopDependencyPlanner::COMMUNITY_FRAMEWORK_ID && ($mod['installed'] ?? false)) {
                $ids[] = $id;
                continue;
            }

            if (!($mod['enabled'] ?? false)) {
                if ($mod['installed'] ?? false) {
                    $disabledInstalledIds[] = $id;
                }
                continue;
            }

            $ids[] = $id;
        }

        foreach ($this->persistedQueue($server) as $entry) {
            $id = trim((string) ($entry['workshop_id'] ?? ''));

            if ($id !== '' && !in_array($id, $disabledInstalledIds, true)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => ctype_digit($id))));
    }

    /**
     * @return list<string>
     */
    private function installedWorkshopIds(mixed $server): array
    {
        $ids = [];

        foreach ($this->installedMods($server) as $mod) {
            $id = trim((string) ($mod['workshop_id'] ?? ''));

            if ($id !== '' && ctype_digit($id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every mod folder actually present in the server root, as a lower-cased
     * key with the leading `@` stripped (e.g. `@CF` -> `cf`).
     *
     * Unlike `installedWorkshopIds()`, this is a raw disk scan independent of
     * whatever `meta.cpp`/`mod.cpp` metadata a folder does or does not carry,
     * so a mod already installed under its friendly folder name is still
     * recognized even when its Workshop ID cannot be read from the folder.
     *
     * @return list<string>
     */
    private function installedFolderKeys(mixed $server): array
    {
        return array_values(array_unique(array_map(
            static fn (array $folder): string => strtolower(ltrim($folder['name'], '@')),
            $this->modFolders($server),
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dependentMods(string $workshopId, mixed $server): array
    {
        if ($workshopId === '') {
            return [];
        }

        $dependents = [];

        foreach ($this->installedMods($server) as $mod) {
            $dependencies = $mod['dependencies'] ?? [];
            $dependencies = is_array($dependencies) ? $dependencies : array_filter(explode(',', (string) $dependencies));
            $dependencies = array_values(array_map('strval', $dependencies));

            if (in_array($workshopId, $dependencies, true)) {
                $dependents[] = [
                    'workshop_id' => (string) ($mod['workshop_id'] ?? ''),
                    'title' => (string) ($mod['title'] ?? ''),
                    'folder_name' => (string) ($mod['folder_name'] ?? ''),
                ];
            }
        }

        return $dependents;
    }

    /**
     * Scans every mod folder still named after its bare numeric Workshop ID
     * (e.g. `@1559212036`) and, once its `meta.cpp`/`mod.cpp` is readable,
     * renames it to a friendly `@ModName` folder and removes the ID from the
     * SteamCMD download variable so it stops being re-queued/re-downloaded.
     *
     * Returns true when at least one folder was renamed, so callers can
     * refresh any cached folder/startup listings before continuing.
     *
     * @param list<array{name: string, size: int}> $folders
     */
    private function renameNumericModFolders(mixed $server, array $folders): bool
    {
        $renamed = false;

        foreach ($folders as $folder) {
            $folderName = (string) ($folder['name'] ?? '');
            $bare = ltrim($folderName, '@');

            if ($bare === '' || !ctype_digit($bare)) {
                continue;
            }

            $meta = $this->modMetadata($server, $folderName);
            $title = (string) ($meta['name'] ?? '');

            if ($title === '') {
                // meta.cpp/mod.cpp not readable yet (mid-download) — nothing to rename.
                continue;
            }

            $newFolderName = $this->maybeRenameFolderToModName($server, $folderName, $title, $bare);

            if ($newFolderName === $folderName) {
                continue;
            }

            $renamed = true;

            $this->startup->removeWorkshopIds(
                $server,
                [$bare],
                $this->modlistWorkshopIds($server),
            );

            $this->configuration->syncTypesExtraForMod($server, $newFolderName, $title);
        }

        if ($renamed) {
            $this->invalidateModsCache($server);
        }

        return $renamed;
    }

    /**
     * @param list<string> $workshopIds
     */
    private function syncInstalledTypesExtra(mixed $server, array $workshopIds): void
    {
        foreach ($workshopIds as $workshopId) {
            $mod = $this->findMod((string) $workshopId, $server);

            if ($mod === null || !($mod['installed'] ?? false)) {
                continue;
            }

            $folderName = (string) ($mod['folder_name'] ?? '');
            $originalFolderName = $folderName;
            $title = (string) ($mod['title'] ?? '');

            // Rename folders still using the numeric Workshop ID as their name
            // (e.g. `@1797720064`) to the proper mod name (`@WindstridesClothingPack`).
            // Gated behind `rename_mods_to_friendly_names` (default off) — see
            // installedMods() for why renaming breaks the egg's own
            // "already installed" detection and causes duplicate re-downloads.
            if ($this->settings->get('rename_mods_to_friendly_names', false)) {
                $folderName = $this->maybeRenameFolderToModName($server, $folderName, $title, (string) $workshopId);
            }

            // When the folder was successfully renamed away from the numeric Workshop ID,
            // remove that ID from the SteamCMD download variable so the next server
            // restart does not re-download the mod into a new @workshopId folder and
            // create a duplicate installation.
            if ($folderName !== $originalFolderName) {
                $this->startup->removeWorkshopIds(
                    $server,
                    [(string) $workshopId],
                    $this->modlistWorkshopIds($server),
                );
            }

            $this->configuration->syncTypesExtraForMod($server, $folderName, $title);
        }
    }

    /**
     * When a mod folder is still named after its Workshop ID (e.g. `@1797720064`),
     * rename it to a clean mod-name folder (`@WindstridesClothingPack`) and
     * update the startup load order to use the new name.
     */
    private function maybeRenameFolderToModName(mixed $server, string $folderName, string $title, string $workshopId): string
    {
        $bare = ltrim($folderName, '@');

        // Only rename when the current folder name IS the numeric Workshop ID.
        if ($bare === '' || !ctype_digit($bare) || $bare !== $workshopId || $title === '') {
            return $folderName;
        }

        $newFolderName = $this->modFolderNameFromTitle($title, $workshopId);

        if ($newFolderName === $folderName) {
            return $folderName;
        }

        if (!$this->gateway->renameFile($server, '/', $folderName, $newFolderName)) {
            // The rename may have failed because the target folder already exists
            // (e.g., a previous rename already created @ModName, and SteamCMD then
            // re-downloaded the mod into @workshopId again on a later update). Check
            // whether the target is present and, if so, replace the stale @ModName
            // folder with the freshly downloaded @workshopId contents instead of
            // discarding the newer download: the numeric folder is always the one
            // SteamCMD just wrote, so it holds the up-to-date mod version, while the
            // named folder may be an outdated copy. Keeping the outdated copy can
            // silently downgrade a mod (e.g. Community Framework) to a version that
            // can no longer read save data written by the newer one, crashing the
            // server in a restart loop.
            $this->gateway->clearFileListingCache($server);
            $this->invalidateModsCache($server);
            $existingFolders = $this->modFolders($server);
            $targetExists = array_filter(
                $existingFolders,
                static fn (array $f): bool => strcasecmp($f['name'], $newFolderName) === 0,
            ) !== [];

            if ($targetExists) {
                if (!$this->gateway->deletePath($server, '/' . $newFolderName)) {
                    // Could not remove the stale folder; keep the numeric folder in
                    // place rather than risk losing the freshly downloaded mod.
                    return $folderName;
                }

                $this->invalidateModsCache($server);

                if (!$this->gateway->renameFile($server, '/', $folderName, $newFolderName)) {
                    // The rename still failed after clearing the stale folder; leave
                    // the numeric folder as-is so the mod keeps loading.
                    return $folderName;
                }

                $this->invalidateModsCache($server);

                // Rewrite the load order to use the named folder so the numeric
                // Workshop ID is removed from startup variables (preventing the
                // egg from re-downloading the mod into @workshopId on every restart).
                $order = $this->enabledFolders($server);
                $updated = $this->replaceLoadOrderReferences($order, $workshopId, $newFolderName);

                if ($updated !== $order) {
                    $this->startup->saveModList($server, $updated);
                    $this->invalidateModsCache($server);
                }

                $this->renameInFullOrder($server, $folderName, $newFolderName);

                return $newFolderName;
            }

            return $folderName;
        }

        // Clear the per-request memo so the next call re-reads the server root.
        $this->invalidateModsCache($server);

        // Rewrite the load order to use the new folder name.
        $order = $this->enabledFolders($server);
        $updated = $this->replaceLoadOrderReferences($order, $workshopId, $newFolderName);

        if ($updated !== $order) {
            $this->startup->saveModList($server, $updated);
            $this->invalidateModsCache($server);
        }

        // Keep the persisted full-order table in sync with the renamed folder.
        $this->renameInFullOrder($server, $folderName, $newFolderName);

        return $newFolderName;
    }

    /**
     * Derives a clean `@FolderName` from a mod title.
     *
     * Spaces become underscores; all non-alphanumeric/underscore characters
     * are stripped. Falls back to the Workshop ID if the result is empty.
     */
    private function modFolderNameFromTitle(string $title, string $workshopId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', trim($title)));

        if ($clean === '' || $clean === null) {
            return '@' . $workshopId;
        }

        return '@' . $clean;
    }

    /**
     * Rewrites every load-order reference for a Workshop ID to the canonical
     * on-disk folder name, collapsing duplicates when both forms are present.
     *
     * @param list<string> $order
     * @return list<string>
     */
    private function replaceLoadOrderReferences(array $order, string $workshopId, string $folderName): array
    {
        $updated = [];
        $seen = [];
        $needle = strtolower($workshopId);

        foreach ($order as $entry) {
            $candidate = ltrim(strtolower(trim($entry)), '@') === $needle
                ? $folderName
                : $entry;
            $key = strtolower($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $updated[] = $candidate;
        }

        return $updated;
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

    // -------------------------------------------------------------------------
    // Full mod-order persistence (dayz_server_mod_order)
    // -------------------------------------------------------------------------

    /**
     * Ensures `dayz_server_mod_order` is populated for this server.
     *
     * On the first call for a server the table is seeded from the current
     * `installedMods()` output — which lists mods in their real startup-command
     * order — so every subsequent enable/disable can reference those positions.
     * On later calls only mods not yet tracked are appended at the end, so newly
     * installed mods are included without disturbing saved positions.
     *
     * All database operations are best-effort: if the table does not exist (old
     * installation, not yet migrated) or any query fails the method is a no-op
     * and `toggle()` falls back to appending.
     */
    private function bootstrapFullOrder(mixed $server): void
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_server_mod_order')) {
                return;
            }

            // Collect the folder names already tracked for this server.
            $tracked = \Illuminate\Support\Facades\DB::table('dayz_server_mod_order')
                ->where('server_id', $serverId)
                ->pluck('folder_name')
                ->map(static fn (mixed $f): string => strtolower(trim((string) $f)))
                ->all();

            $initialFill = $tracked === [];

            // When the table is fresh the maximum position is -1 so the first
            // row gets position 0. When rows already exist new entries are
            // appended after the highest existing position.
            $nextPos = $initialFill
                ? 0
                : ((int) \Illuminate\Support\Facades\DB::table('dayz_server_mod_order')
                    ->where('server_id', $serverId)
                    ->max('position')) + 1;

            foreach ($this->installedMods($server) as $mod) {
                $folder = trim((string) ($mod['folder_name'] ?? ''));

                if ($folder === '' || in_array(strtolower($folder), $tracked, true)) {
                    continue;
                }

                // For the initial fill use the position from `installedMods()` so
                // mods appear in their true startup-command order (0, 1, 2, …).
                // For subsequent calls (new mods added later) just append.
                $position = $initialFill ? (int) ($mod['position'] ?? $nextPos) : $nextPos;

                \Illuminate\Support\Facades\DB::table('dayz_server_mod_order')->insertOrIgnore([
                    'server_id'   => $serverId,
                    'folder_name' => $folder,
                    'position'    => $position,
                    'enabled'     => ($mod['enabled'] ?? false) ? 1 : 0,
                ]);

                $tracked[] = strtolower($folder);
                $nextPos   = max($nextPos, $position) + 1;
            }
        } catch (Throwable) {
            // Best-effort: toggle() still works via the append fallback.
        }
    }

    /**
     * Returns the index in `$currentEnabledOrder` before which `$targetFolder`
     * should be inserted when it is re-enabled, based on the positions saved in
     * `dayz_server_mod_order`.
     *
     * The method walks forward from the target's saved position and returns the
     * index of the first saved folder that is currently enabled.  When no such
     * successor exists the caller should append to the end.
     *
     * @param  list<string> $currentEnabledOrder
     */
    private function savedInsertPosition(mixed $server, string $targetFolder, array $currentEnabledOrder): ?int
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return null;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_server_mod_order')) {
                return null;
            }

            // Retrieve all rows for this server sorted by their saved position.
            $rows = \Illuminate\Support\Facades\DB::table('dayz_server_mod_order')
                ->where('server_id', $serverId)
                ->orderBy('position')
                ->pluck('folder_name')
                ->all();

            if ($rows === []) {
                return null;
            }

            // Locate the target folder.
            $targetIdx = null;

            foreach ($rows as $i => $row) {
                if (strcasecmp((string) $row, $targetFolder) === 0) {
                    $targetIdx = $i;
                    break;
                }
            }

            if ($targetIdx === null) {
                return null;
            }

            // Walk forward from the target's position and find the first saved
            // folder that still exists in the current enabled list.
            for ($i = $targetIdx + 1; $i < count($rows); $i++) {
                $savedFolder = (string) $rows[$i];

                foreach ($currentEnabledOrder as $k => $enabledFolder) {
                    if (strcasecmp($enabledFolder, $savedFolder) === 0) {
                        return $k; // Insert before this enabled mod.
                    }
                }
            }
        } catch (Throwable) {
            // Fall through to the null (append) result.
        }

        return null;
    }

    /**
     * Updates the `enabled` flag for a single folder in the persisted full-order
     * table without changing its position.
     */
    private function updateFullOrderEnabled(mixed $server, string $folder, bool $enabled): void
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_server_mod_order')) {
                return;
            }

            \Illuminate\Support\Facades\DB::table('dayz_server_mod_order')
                ->where('server_id', $serverId)
                ->whereRaw('LOWER(folder_name) = ?', [strtolower($folder)])
                ->update(['enabled' => $enabled ? 1 : 0]);
        } catch (Throwable) {
            // Best-effort.
        }
    }

    /**
     * Removes a folder from the persisted full-order table (called when a mod
     * is permanently deleted so re-installing it starts fresh).
     */
    private function removeFromFullOrder(mixed $server, string $folder): void
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_server_mod_order')) {
                return;
            }

            \Illuminate\Support\Facades\DB::table('dayz_server_mod_order')
                ->where('server_id', $serverId)
                ->whereRaw('LOWER(folder_name) = ?', [strtolower($folder)])
                ->delete();
        } catch (Throwable) {
            // Best-effort.
        }
    }

    /**
     * Renames a folder in the persisted full-order table (called after a
     * numeric `@workshopId` folder is renamed to `@ModName`).
     */
    private function renameInFullOrder(mixed $server, string $oldFolder, string $newFolder): void
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_server_mod_order')) {
                return;
            }

            \Illuminate\Support\Facades\DB::table('dayz_server_mod_order')
                ->where('server_id', $serverId)
                ->whereRaw('LOWER(folder_name) = ?', [strtolower($oldFolder)])
                ->update(['folder_name' => $newFolder]);
        } catch (Throwable) {
            // Best-effort.
        }
    }
}
