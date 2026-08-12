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
 *   #include "$CurrentDir:mpmissions/dayzOffline.chernarusplus/pteromods_live_map.c";
 *   PteroMods_LiveMap_Init();
 *
 * No database tables are used; everything is file-based.
 */
final class DayZLiveBridgeService
{
    /** Fallback mission directory when auto-detection fails. */
    private const DEFAULT_MISSION_PATH = '/mpmissions/dayzOffline.chernarusplus';

    /** Script filename deployed into the active mission directory. */
    private const MISSION_SCRIPT_FILE = 'pteromods_live_map.c';

    /** init.c filename inside the active mission directory. */
    private const INIT_C_FILE = 'init.c';

    /**
     * Marker string used to detect whether the activation block has already
     * been appended to init.c so we never add it twice.
     */
    private const INIT_C_MARKER = 'PteroMods_LiveMap_Init';

    /**
     * Include directive placed at the very top of init.c.
     *
     * EnfScript only allows declarations at file scope, so the bridge is
     * activated by a call inside main() (see INIT_C_CALL) rather than by a
     * statement next to the include — a bare call at file scope makes the
     * mission fail to compile and the server refuses to load it.
     */
    private const INIT_C_INCLUDE_TEMPLATE = "// PteroMods Live Map Bridge – added automatically by the PteroMods panel.\n// Remove this line, the PteroMods_LiveMap_Init() call in main() and\n// pteromods_live_map.c to disable the live map.\n#include \"\$CurrentDir:%s\";\n";

    /** Activation call injected at the start of the mission's main() function. */
    private const INIT_C_CALL = "\n\t// PteroMods Live Map Bridge – added automatically by the PteroMods panel.\n\tPteroMods_LiveMap_Init();\n";

    /** Matches the mission's main() entry point so the call can be injected into it. */
    private const INIT_C_MAIN_PATTERN = '/\bvoid\s+main\s*\(\s*\)\s*\{/';

    /**
     * Matches the broken activation block written by panel versions that put the
     * call at file scope, which makes the mission fail to compile. Detecting it
     * lets the panel repair such servers automatically.
     */
    private const INIT_C_INCLUDE_PATTERN = '/^\s*#include\s+"(?:(?:\$CurrentDir:)?(?:mpmissions\/)?[^"\/]+\/)?pteromods_live_map\.c"\s*;?\s*$/mi';

    private const INIT_C_CALL_PATTERN = '/^\s*PteroMods_LiveMap_Init\s*\(\s*\)\s*;\s*$/mi';

    private const INIT_C_LEGACY_PATTERN = '/#include\s+"(?:(?:\$CurrentDir:)?(?:mpmissions\/)?[^"\/]+\/)?pteromods_live_map\.c"\s*;?\s*\n\s*PteroMods_LiveMap_Init\(\);/';

    /** Path inside the container where the JSON snapshot lives. */
    private const SNAPSHOT_PATH = '/profiles/PteroMods/live_map_players.json';

    /** Local filesystem path to the EnfScript (.c) template bundled with this module. */
    private const TEMPLATE_C_PATH = __DIR__ . '/../assets/bridge/pteromods_live_map.c';

    /** Empty/initial snapshot written when the file does not yet exist. */
    private const EMPTY_SNAPSHOT = '{"updated_at":"","mapName":"","players":[]}';

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
        $missionScriptPath = $this->missionScriptPath($server);
        $initCPath = $this->initCPath($server);
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
        $scriptOk = $this->gateway->writeFile($server, $missionScriptPath, $template);

        // Only append the activation block to init.c when the script was
        // successfully written.  Appending the #include without the file causes
        // a "Can't find file 'pteromods_live_map.c'" compile error on the next
        // server start.
        $initCOk = $scriptOk && $this->ensureInitC($server, $initCPath, $this->initCInclude($server));

        // Initialise the JSON snapshot file (only when absent).
        $snapshotOk = $this->ensureSnapshot($server);

        $deployed = $scriptOk && $initCOk && $snapshotOk;

        if ($deployed) {
            $message = 'Bridge deployed. The activation block has been added to init.c — '
                . 'restart the server to start writing player snapshots.';
        } elseif ($scriptOk && $initCOk) {
            $message = 'Bridge script and init.c updated, but snapshot initialisation failed '
                . '(check Wings connectivity and /profiles/PteroMods/ directory permissions).';
        } elseif ($scriptOk) {
            $message = 'Bridge script written but init.c could not be updated automatically '
                . '(no "void main()" was found, or Wings is unreachable). Add the #include line at the '
                . 'top of init.c and call PteroMods_LiveMap_Init(); inside main() manually — see the README.';
        } else {
            $message = 'Bridge deployment failed. Check that Wings is reachable and the server is not suspended.';
        }

        return [
            'deployed'        => $deployed,
            'script'          => $scriptOk,
            'init_c'          => $initCOk,
            'snapshot'        => $snapshotOk,
            'message'         => $message,
            'script_path'     => $missionScriptPath,
            'init_c_path'     => $initCPath,
            'snapshot_path'   => self::SNAPSHOT_PATH,
            'init_c_include'  => trim($this->initCInclude($server)),
            'init_c_call'     => trim(self::INIT_C_CALL),
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
        $initCOk  = $this->stripInitC($server, $this->initCPath($server));
        $scriptOk = $this->gateway->deletePath($server, $this->missionScriptPath($server));

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
     * @return array{deployed: bool, script: bool, init_c: bool, init_c_include: bool, init_c_legacy: bool, snapshot: bool}
     */
    public function status(mixed $server): array
    {
        $missionScriptPath = $this->missionScriptPath($server);
        $initCPath = $this->initCPath($server);

        $script   = $this->gateway->readFile($server, $missionScriptPath);
        $initC    = $this->gateway->readFile($server, $initCPath);
        $snapshot = $this->gateway->readFile($server, self::SNAPSHOT_PATH);

        $scriptPresent   = is_string($script)   && trim($script)   !== '';
        $initCActivated  = is_string($initC)    && preg_match(self::INIT_C_CALL_PATTERN, $initC) === 1;
        $initCInclude    = is_string($initC)    && preg_match(self::INIT_C_INCLUDE_PATTERN, $initC) === 1;
        $snapshotPresent = is_string($snapshot)  && trim($snapshot) !== '';

        $initCLegacy = is_string($initC) && preg_match(self::INIT_C_LEGACY_PATTERN, $initC) === 1;

        return [
            'deployed'      => $scriptPresent && $initCActivated && $initCInclude && $snapshotPresent && !$initCLegacy,
            'script'        => $scriptPresent,
            'init_c'        => $initCActivated,
            'init_c_include'=> $initCInclude,
            'init_c_legacy' => $initCLegacy,
            'snapshot'      => $snapshotPresent,
            'script_path'   => $missionScriptPath,
            'init_c_path'   => $initCPath,
            'snapshot_path' => self::SNAPSHOT_PATH,
        ];
    }

    /**
     * Strips the PteroMods activation block from init.c if it is present.
     * Returns true when the block is absent or was successfully removed.
     */
    private function stripInitC(mixed $server, string $initCPath): bool
    {
        $existing = $this->gateway->readFile($server, $initCPath);

        if (!is_string($existing)) {
            return true;
        }

        $hasBridgeBlock = str_contains($existing, self::INIT_C_MARKER)
            || preg_match(self::INIT_C_INCLUDE_PATTERN, $existing) === 1
            || str_contains($existing, 'PteroMods Live Map Bridge');

        if (!$hasBridgeBlock) {
            return true;
        }

        // Remove every line PteroMods added: the include, the activation call
        // and the explanatory comments around them. This also cleans up blocks
        // written by older panel versions that appended the call at file scope.
        $lines = explode("\n", $existing);
        $kept  = array_filter($lines, static function (string $line): bool {
            return !str_contains($line, 'PteroMods_LiveMap')
                && !str_contains($line, 'pteromods_live_map')
                && !str_contains($line, 'PteroMods Live Map Bridge')
                && !str_contains($line, '// Remove this block')
                && !str_contains($line, '// Remove this line, the');
        });
        $stripped = implode("\n", $kept);

        return $this->gateway->writeFile($server, $initCPath, $stripped);
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

    private function missionPath(mixed $server): string
    {
        // The live-map bridge is intentionally anchored to the vanilla Chernarus
        // mission path so init.c always includes:
        // $CurrentDir:mpmissions/dayzOffline.chernarusplus/pteromods_live_map.c
        // Keep the $server parameter for signature compatibility and future use.
        return self::DEFAULT_MISSION_PATH;
    }

    private function missionScriptPath(mixed $server): string
    {
        return rtrim($this->missionPath($server), '/') . '/' . self::MISSION_SCRIPT_FILE;
    }

    private function initCPath(mixed $server): string
    {
        return rtrim($this->missionPath($server), '/') . '/' . self::INIT_C_FILE;
    }

    private function initCInclude(mixed $server): string
    {
        $scriptPath = ltrim($this->missionScriptPath($server), '/');

        return sprintf(self::INIT_C_INCLUDE_TEMPLATE, $scriptPath);
    }

    /**
     * Reads init.c from the container (creating an empty file if absent) and
     * appends the PteroMods activation block when it is not already present.
     * Returns true when init.c contains (or now contains) the block.
     */
    private function ensureInitC(mixed $server, string $initCPath, string $includeBlock): bool
    {
        $existing = $this->gateway->readFile($server, $initCPath);
        $content  = is_string($existing) ? $existing : '';

        $content = preg_replace('/^\s*#include\s+"(?:(?:\$CurrentDir:)?(?:mpmissions\/)?[^"\/]+\/)?pteromods_live_map\.c"\s*;?\s*\R?/mi', '', $content) ?? $content;
        $content = preg_replace('/^\s*\/\/\s*PteroMods Live Map Bridge.*\R?/mi', '', $content) ?? $content;
        $content = preg_replace('/^\s*\/\/\s*Remove this line, the.*\R?/mi', '', $content) ?? $content;
        $content = preg_replace(self::INIT_C_CALL_PATTERN, '', $content) ?? $content;

        $content = $includeBlock . ltrim($content);

        // The activation call must live inside main(); without it the mission
        // either fails to compile (bare call at file scope) or never starts the
        // bridge, which is why the panel refuses to write a half-finished block.
        if (preg_match(self::INIT_C_MAIN_PATTERN, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        $insertAt = (int) $match[0][1] + strlen((string) $match[0][0]);
        $updated  = substr($content, 0, $insertAt)
            . self::INIT_C_CALL
            . substr($content, $insertAt);

        return $this->gateway->writeFile($server, $initCPath, $updated);
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
