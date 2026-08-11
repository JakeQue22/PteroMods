<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Persists and retrieves global DayZ Manager panel-level settings.
 *
 * Settings are stored as key/value rows in `dayz_manager_settings` so they
 * survive upgrades and apply across all servers. Every setting has a typed
 * default that is returned when the table does not yet exist (pre-migration)
 * or when the row has never been written.
 */
final class DayZManagerSettingsService
{
    /**
     * Defaults for every known setting key.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        /**
         * When false (the default) DayZ Manager never reads from a Steam
         * account's Workshop subscription list. Mods are only installed when
         * an operator explicitly queues them through the Install panel.
         *
         * Set to true to allow DayZ Manager to utilise the Steam account's
         * Workshop subscription list (requires a Steam Web API key with the
         * appropriate permissions to be configured).
         */
        'use_workshop_subscriptions' => false,

        /**
         * Steam Web API key used for Workshop browsing and metadata lookups.
         * Can also be supplied via the STEAM_WEB_API_KEY environment variable.
         */
        'steam_web_api_key' => '',

        /**
         * When false (the default) DayZ Manager never renames a mod's
         * numeric Workshop ID folder (e.g. `@1797720064`) to a friendly
         * name (e.g. `@CF`). This keeps every mod folder in the exact form
         * the DayZ egg's container startup script expects when deciding
         * whether a mod is already installed, so nothing gets silently
         * re-downloaded into a duplicate `@<id>` folder on restart.
         *
         * Set to true to allow DayZ Manager to rename installed mod
         * folders to their friendly Workshop title for a nicer file
         * listing/load order — but be aware the game egg's startup script
         * (running inside the Docker image, outside this panel) only
         * recognises the numeric folder name, so it will re-download the
         * mod under `@<id>` on every restart once its folder is renamed.
         */
        'rename_mods_to_friendly_names' => false,

        /**
         * When false (the default) DayZ Manager never contacts the DZSA
         * Launcher service on its own. Mods installed while a server is
         * offline are not picked up by DZSA until the server restarts a
         * second time (once to download the mod, once more for the egg's
         * startup script to actually enable it) — set this to true so DayZ
         * Manager automatically submits a server-check request to
         * https://dayzsalauncher.com once that second restart finishes and
         * the server reports itself running, instead of requiring an
         * operator to click "Update DZSA Server Listing" manually.
         */
        'auto_submit_dzsa' => false,

        /**
         * When false (the default) DayZ Manager never restarts a server on
         * its own after mods finish downloading. The DayZ egg's startup
         * script only picks up newly-downloaded mods on the boot *after*
         * they land on disk, so a mod queued and installed during a restart
         * still needs a second restart before it is actually active.
         *
         * Set to true to have DayZ Manager automatically trigger that
         * second restart as soon as it detects the queued mods have
         * finished downloading and been added to the load order, instead of
         * requiring an operator to notice and restart manually.
         */
        'auto_restart_after_mod_install' => false,

        /**
         * When enabled, DayZ Manager periodically removes old profile log files
         * from `/profiles` using the configured retention window.
         */
        'auto_scrub_profile_logs' => false,

        /**
         * Number of days `script_*.log` files are kept before auto-scrub deletes them.
         */
        'script_log_retention_days' => 14,

        /**
         * Number of days `crash_*.log` files are kept before auto-scrub deletes them.
         */
        'crash_log_retention_days' => 14,

        /**
         * Number of days `TM_GeneralLogs_*.log` files are kept before auto-scrub deletes them.
         */
        'tm_general_log_retention_days' => 14,

        /**
         * Shared secret used by the optional server-side DayZ live-map bridge.
         */
        'live_map_bridge_secret' => '',
    ];

    /**
     * Human-readable labels shown on the Settings page.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'use_workshop_subscriptions' => 'Use Steam account Workshop subscription list',
        'rename_mods_to_friendly_names' => 'Rename mod folders to their friendly Workshop name',
        'auto_submit_dzsa' => 'Automatically submit to DZSA Launcher after mod installs',
        'auto_restart_after_mod_install' => 'Automatically restart the server once queued mods finish installing',
        'auto_scrub_profile_logs' => 'Automatically scrub old profile logs in /profiles',
    ];

    /**
     * Human-readable labels for text/string settings shown on the Settings page.
     *
     * @var array<string, string>
     */
    public const TEXT_LABELS = [
        'steam_web_api_key' => 'Steam Web API Key',
        'script_log_retention_days' => 'script_*.log retention (days)',
        'crash_log_retention_days' => 'crash_*.log retention (days)',
        'tm_general_log_retention_days' => 'TM_GeneralLogs_*.log retention (days)',
        'live_map_bridge_secret' => 'Live Map bridge secret (optional)',
    ];

    /**
     * Descriptions shown below each toggle on the Settings page.
     *
     * @var array<string, string>
     */
    public const DESCRIPTIONS = [
        'use_workshop_subscriptions' =>
            'When enabled, DayZ Manager will use the configured Steam account\'s Workshop '
            . 'subscription list to suggest or automatically import subscribed mods. '
            . 'Disabled by default — enable only if you want subscribed mods to influence '
            . 'what appears in the manager.',
        'steam_web_api_key' =>
            'Your Steam Web API key for browsing the Workshop and resolving mod metadata. '
            . 'Get a free key at steamcommunity.com/dev/apikey. '
            . 'Can also be set via the STEAM_WEB_API_KEY environment variable.',
        'rename_mods_to_friendly_names' =>
            'Disabled by default. Keeps every mod folder named after its numeric Steam '
            . 'Workshop ID (e.g. "@1797720064"), matching what the DayZ egg\'s container '
            . 'startup script expects when it checks whether a mod is already downloaded. '
            . 'Enabling this lets DayZ Manager rename installed mod folders to their '
            . 'friendly Workshop title (e.g. "@CF") for a nicer file listing, but the egg\'s '
            . 'startup script only recognises the numeric folder name — once renamed, it '
            . 'will re-download that mod into a new duplicate "@<id>" folder on every '
            . 'server restart. Only enable this if your egg/image has its own logic that '
            . 'tolerates renamed mod folders.',
        'auto_submit_dzsa' =>
            'Disabled by default. New mods only start downloading after the server restarts, '
            . 'and the DayZ egg\'s startup script needs a second restart before it actually '
            . 'enables them — DZSA Launcher will not show the correct mod list until then. '
            . 'When enabled, DayZ Manager watches for a mod-install restart to complete and, '
            . 'once the server reports itself running again, automatically sends a '
            . 'server-check request to dayzsalauncher.com so the launcher refreshes the '
            . 'mod listing without you needing to open the DZSA tab and click the button '
            . 'yourself.',
        'auto_restart_after_mod_install' =>
            'Disabled by default. The DayZ egg only downloads newly queued mods on the boot '
            . 'right after they are added, then needs one more restart before its startup '
            . 'script actually enables them. When enabled, DayZ Manager watches the install '
            . 'queue and, as soon as it detects the queued mods have finished downloading '
            . 'and been added to the enabled load order, automatically triggers that second '
            . 'restart for you (connected players will be disconnected). Leave disabled if '
            . 'you would rather restart manually when convenient.',
        'auto_scrub_profile_logs' =>
            'Disabled by default. When enabled, DayZ Manager periodically scans `/profiles` and '
            . 'deletes old `script_*.log`, `crash_*.log`, and `TM_GeneralLogs_*.log` files older '
            . 'than each type\'s configured retention window.',
        'script_log_retention_days' =>
            'How many days to keep `script_*.log` files before automatic cleanup removes them.',
        'crash_log_retention_days' =>
            'How many days to keep `crash_*.log` files before automatic cleanup removes them.',
        'tm_general_log_retention_days' =>
            'How many days to keep `TM_GeneralLogs_*.log` files before automatic cleanup removes them.',
        'live_map_bridge_secret' =>
            'Optional shared secret for a server-side DayZ script to write player snapshots to '
            . 'the Live Map bridge file securely. Leave blank if your bridge writes directly to '
            . '`/profiles/PteroMods/live_map_players.json` without secret signing.',
    ];

    /**
     * Retrieves a setting value, returning the typed default when the row is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $fallback = array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[$key] : $default;

        if (!$this->tableExists()) {
            return $fallback;
        }

        try {
            $row = \Illuminate\Support\Facades\DB::table('dayz_manager_settings')
                ->where('key', $key)
                ->value('value');

            if ($row === null) {
                return $fallback;
            }

            return $this->decode((string) $row, $fallback);
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * Persists a setting value.
     */
    public function set(string $key, mixed $value): void
    {
        if (!$this->tableExists()) {
            return;
        }

        try {
            \Illuminate\Support\Facades\DB::table('dayz_manager_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value'      => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable) {
            // Best-effort: silently ignore write failures.
        }
    }

    /**
     * All settings merged with their defaults, for display on the Settings page.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $settings = self::DEFAULTS;

        if (!$this->tableExists()) {
            return $settings;
        }

        try {
            $rows = \Illuminate\Support\Facades\DB::table('dayz_manager_settings')
                ->get(['key', 'value'])
                ->all();

            foreach ($rows as $row) {
                $row = (array) $row;
                $key = (string) ($row['key'] ?? '');

                if ($key !== '' && array_key_exists($key, $settings)) {
                    $settings[$key] = $this->decode((string) ($row['value'] ?? ''), $settings[$key]);
                }
            }
        } catch (Throwable) {
            // Fall through: return defaults.
        }

        return $settings;
    }

    /**
     * Saves multiple settings at once from a key/value map.
     *
     * @param array<string, mixed> $values
     */
    public function saveMany(array $values): void
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $values)) {
                $this->set($key, $values[$key]);
            }
        }
    }

    private function tableExists(): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_manager_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function decode(string $json, mixed $default): mixed
    {
        if ($json === '') {
            return $default;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $default;
        }
    }
}
