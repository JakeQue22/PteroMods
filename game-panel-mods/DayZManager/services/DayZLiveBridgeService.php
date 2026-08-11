<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Deploys the PteroMods server-side live-map bridge to a DayZ Standalone server
 * container and activates it by appending the required lines to the mission's
 * init.c (mpmissions/dayzOffline.chernarusplus/init.c).
 *
 * The bridge is a DayZ Standalone EnfScript (.c) that runs inside the game server
 * process and writes a JSON player snapshot to
 * /profiles/PteroMods/live_map_players.json every few seconds, which the panel
 * then reads via the Wings file API.
 *
 * Deployment writes or updates the following files in the server container:
 *   mpmissions/dayzOffline.chernarusplus/pteromods_live_map.c   ← bridge script
 *   mpmissions/dayzOffline.chernarusplus/init.c                 ← activation appended
 *   /profiles/PteroMods/live_map_players.json                   ← initialised empty snapshot
 *
 * The activation block appended to init.c (only when not already present):
 *   #include "pteromods_live_map.c"
 *   PteroMods_LiveMap_Init();
 *
 * No database tables are used; everything is file-based.
 */
final class DayZLiveBridgeService
{
    /** Path inside the container where the EnfScript bridge script is deployed. */
    private const MISSION_SCRIPT_PATH = '/mpmissions/dayzOffline.chernarusplus/pteromods_live_map.c';

    /** Path inside the container where the DayZ mission's init.c lives. */
    private const INIT_C_PATH = '/mpmissions/dayzOffline.chernarusplus/init.c';

    /**
     * Marker string used to detect whether the activation block has already
     * been appended to init.c so we never add it twice.
     */
    private const INIT_C_MARKER = 'PteroMods_LiveMap_Init';

    /**
     * Block appended to init.c to include the bridge and call the init function.
     * The #include must precede the call so EnfScript can resolve the symbol.
     */
    private const INIT_C_BLOCK = "\n// PteroMods Live Map Bridge – added automatically by the PteroMods panel.\n// Remove this block (and pteromods_live_map.c) to disable the live map.\n#include \"pteromods_live_map.c\"\nPteroMods_LiveMap_Init();\n";

    /** Path inside the container where the JSON snapshot lives. */
    private const SNAPSHOT_PATH = '/profiles/PteroMods/live_map_players.json';

    /** Local filesystem path to the EnfScript (.c) template bundled with this module. */
    private const TEMPLATE_C_PATH = __DIR__ . '/../assets/bridge/pteromods_live_map.c';

    /** Empty/initial snapshot written when the file does not yet exist. */
    private const EMPTY_SNAPSHOT = '{"updated_at":"","map":"","players":[]}';

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * Deploys the EnfScript bridge to the mission directory, appends the
     * activation block to init.c (if not already present), and initialises
     * the JSON snapshot file.
     *
     * Safe to call repeatedly: init.c is only modified when the activation
     * block is absent, and the snapshot file is only written when it does not
     * yet exist.
     *
     * @return array{deployed: bool, script: bool, init_c: bool, snapshot: bool, message: string}
     */
    public function deploy(mixed $server): array
    {
        $template = $this->loadCTemplate();

        if ($template === null) {
            return [
                'deployed' => false,
                'script'   => false,
                'init_c'   => false,
                'snapshot' => false,
                'message'  => 'Bridge template (pteromods_live_map.c) not found. Re-run the panel install.sh.',
            ];
        }

        // Deploy the EnfScript bridge to the mission folder.
        $scriptOk = $this->gateway->writeFile($server, self::MISSION_SCRIPT_PATH, $template);

        // Only append the activation block to init.c when the script was
        // successfully written.  Appending the #include without the file causes
        // a "Can't find file 'pteromods_live_map.c'" compile error on the next
        // server start.
        $initCOk = $scriptOk && $this->ensureInitC($server);

        // Initialise the JSON snapshot file (only when absent).
        $snapshotOk = $this->ensureSnapshot($server);

        $deployed = $scriptOk && $initCOk && $snapshotOk;

        if ($deployed) {
            $message = 'Bridge deployed. The activation block has been appended to init.c — '
                . 'restart the server to start writing player snapshots.';
        } elseif ($scriptOk && $initCOk) {
            $message = 'Bridge script and init.c updated, but snapshot initialisation failed '
                . '(check Wings connectivity and /profiles/PteroMods/ directory permissions).';
        } elseif ($scriptOk) {
            $message = 'Bridge script written but init.c could not be updated '
                . '(check Wings connectivity and that the mission folder exists).';
        } else {
            $message = 'Bridge deployment failed. Check that Wings is reachable and the server is not suspended.';
        }

        return [
            'deployed'        => $deployed,
            'script'          => $scriptOk,
            'init_c'          => $initCOk,
            'snapshot'        => $snapshotOk,
            'message'         => $message,
            'script_path'     => self::MISSION_SCRIPT_PATH,
            'init_c_path'     => self::INIT_C_PATH,
            'snapshot_path'   => self::SNAPSHOT_PATH,
            'init_c_block'    => trim(self::INIT_C_BLOCK),
        ];
    }

    /**
     * Removes the PteroMods bridge from the server container: strips the
     * activation block from init.c and deletes the bridge script.
     *
     * This is used to recover a server that has the #include line in init.c
     * but is missing the actual pteromods_live_map.c file, which causes a
     * "Can't find file 'pteromods_live_map.c'" compile error at server start.
     *
     * Safe to call on servers that were never deployed: it is a no-op when
     * neither the marker nor the script are present.
     *
     * @return array{undeployed: bool, init_c: bool, script: bool, message: string}
     */
    public function undeploy(mixed $server): array
    {
        $initCOk  = $this->stripInitC($server);
        $scriptOk = $this->gateway->deletePath($server, self::MISSION_SCRIPT_PATH);

        $undeployed = $initCOk && $scriptOk;

        return [
            'undeployed' => $undeployed,
            'init_c'     => $initCOk,
            'script'     => $scriptOk,
            'message'    => $undeployed
                ? 'Bridge removed. Restart the server to apply the change.'
                : 'Partial undeploy — check Wings connectivity and retry.',
        ];
    }

    /**
     * Returns the deployment status for a server without writing anything.
     *
     * @return array{deployed: bool, script: bool, init_c: bool, snapshot: bool}
     */
    public function status(mixed $server): array
    {
        $script   = $this->gateway->readFile($server, self::MISSION_SCRIPT_PATH);
        $initC    = $this->gateway->readFile($server, self::INIT_C_PATH);
        $snapshot = $this->gateway->readFile($server, self::SNAPSHOT_PATH);

        $scriptPresent   = is_string($script)   && trim($script)   !== '';
        $initCActivated  = is_string($initC)    && str_contains($initC, self::INIT_C_MARKER);
        $snapshotPresent = is_string($snapshot)  && trim($snapshot) !== '';

        return [
            'deployed'      => $scriptPresent && $initCActivated && $snapshotPresent,
            'script'        => $scriptPresent,
            'init_c'        => $initCActivated,
            'snapshot'      => $snapshotPresent,
            'script_path'   => self::MISSION_SCRIPT_PATH,
            'init_c_path'   => self::INIT_C_PATH,
            'snapshot_path' => self::SNAPSHOT_PATH,
        ];
    }

    /**
     * Strips the PteroMods activation block from init.c if it is present.
     * Returns true when the block is absent or was successfully removed.
     */
    private function stripInitC(mixed $server): bool
    {
        $existing = $this->gateway->readFile($server, self::INIT_C_PATH);

        if (!is_string($existing) || !str_contains($existing, self::INIT_C_MARKER)) {
            return true;
        }

        // Remove every line that belongs to the appended block.  The block
        // begins with the comment line and ends after PteroMods_LiveMap_Init();
        $stripped = preg_replace(
            '/\n\/\/ PteroMods Live Map Bridge[^\n]*\n\/\/ Remove this block[^\n]*\n#include "pteromods_live_map\.c"\nPteroMods_LiveMap_Init\(\);\n/',
            '',
            $existing,
        );

        if ($stripped === null || str_contains($stripped, self::INIT_C_MARKER)) {
            // Regex did not match (block was edited by hand) — fall back to a
            // line-by-line filter that removes every line containing the marker.
            $lines   = explode("\n", $existing);
            $kept    = array_filter($lines, static fn (string $l): bool => !str_contains($l, 'PteroMods_LiveMap') && !str_contains($l, 'pteromods_live_map'));
            $stripped = implode("\n", $kept);
        }

        return $this->gateway->writeFile($server, self::INIT_C_PATH, $stripped);
    }

    /**
     * Reads the bundled EnfScript (.c) template from the module's assets directory.
     */
    private function loadCTemplate(): ?string
    {
        if (!is_file(self::TEMPLATE_C_PATH)) {
            return null;
        }

        $content = file_get_contents(self::TEMPLATE_C_PATH);

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * Reads init.c from the container (creating an empty file if absent) and
     * appends the PteroMods activation block when it is not already present.
     * Returns true when init.c contains (or now contains) the block.
     */
    private function ensureInitC(mixed $server): bool
    {
        $existing = $this->gateway->readFile($server, self::INIT_C_PATH);
        $content  = is_string($existing) ? $existing : '';

        // Nothing to do if the marker is already present.
        if (str_contains($content, self::INIT_C_MARKER)) {
            return true;
        }

        $updated = $content . self::INIT_C_BLOCK;

        return $this->gateway->writeFile($server, self::INIT_C_PATH, $updated);
    }

    /**
     * Writes an initial empty JSON snapshot only when the file does not already
     * exist in the container, so live data is never clobbered.
     */
    private function ensureSnapshot(mixed $server): bool
    {
        $existing = $this->gateway->readFile($server, self::SNAPSHOT_PATH);

        if (is_string($existing) && trim($existing) !== '') {
            return true;
        }

        return $this->gateway->writeFile($server, self::SNAPSHOT_PATH, self::EMPTY_SNAPSHOT);
    }
}
