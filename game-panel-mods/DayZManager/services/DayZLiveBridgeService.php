<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Deploys the PteroMods server-side live-map bridge script to a DayZ server
 * container and verifies whether the bridge files are already present.
 *
 * The bridge is a DayZ SQF script that runs inside the game server process and
 * writes a JSON player snapshot to /profiles/PteroMods/live_map_players.json
 * every few seconds, which the panel then reads via the Wings file API.
 *
 * Deployment writes two files to the server container:
 *   /profiles/PteroMods/pteromods_live_map.sqf   ← the runnable bridge script
 *   /profiles/PteroMods/live_map_players.json     ← initialised as an empty snapshot
 *
 * No database tables are used; everything is file-based.
 */
final class DayZLiveBridgeService
{
    /** Path inside the container where the bridge script is written. */
    private const BRIDGE_SCRIPT_PATH = '/profiles/PteroMods/pteromods_live_map.sqf';

    /** Path inside the container where the JSON snapshot lives. */
    private const SNAPSHOT_PATH = '/profiles/PteroMods/live_map_players.json';

    /** Local filesystem path to the SQF template bundled with this module. */
    private const TEMPLATE_PATH = __DIR__ . '/../assets/bridge/pteromods_live_map.sqf';

    /** Empty/initial snapshot written when the file does not yet exist. */
    private const EMPTY_SNAPSHOT = '{"updated_at":"","map":"","players":[]}';

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * Deploys the bridge script and initialises the snapshot file.
     *
     * Safe to call repeatedly: the snapshot file is only written when it does
     * not already exist so that live data is never clobbered.
     *
     * @return array{deployed: bool, script: bool, snapshot: bool, message: string}
     */
    public function deploy(mixed $server): array
    {
        $template = $this->loadTemplate();

        if ($template === null) {
            return [
                'deployed' => false,
                'script'   => false,
                'snapshot' => false,
                'message'  => 'Bridge template file not found. Re-run the panel install.sh.',
            ];
        }

        $scriptOk   = $this->gateway->writeFile($server, self::BRIDGE_SCRIPT_PATH, $template);
        $snapshotOk = $this->ensureSnapshot($server);

        $deployed = $scriptOk && $snapshotOk;

        return [
            'deployed' => $deployed,
            'script'   => $scriptOk,
            'snapshot' => $snapshotOk,
            'message'  => $deployed
                ? 'Bridge deployed. Add the execVM line to your mission init.sqf to activate it.'
                : ($scriptOk
                    ? 'Bridge script written but snapshot initialisation failed (check Wings connectivity).'
                    : 'Bridge deployment failed. Check that Wings is reachable and the server is not suspended.'),
            'script_path'   => self::BRIDGE_SCRIPT_PATH,
            'snapshot_path' => self::SNAPSHOT_PATH,
            'init_sqf_line' => 'if (isServer) then { execVM "profiles\\PteroMods\\pteromods_live_map.sqf"; };',
        ];
    }

    /**
     * Returns the deployment status for a server without writing anything.
     *
     * @return array{deployed: bool, script: bool, snapshot: bool}
     */
    public function status(mixed $server): array
    {
        $script   = $this->gateway->readFile($server, self::BRIDGE_SCRIPT_PATH);
        $snapshot = $this->gateway->readFile($server, self::SNAPSHOT_PATH);

        $scriptPresent   = is_string($script)   && trim($script)   !== '';
        $snapshotPresent = is_string($snapshot)  && trim($snapshot) !== '';

        return [
            'deployed' => $scriptPresent && $snapshotPresent,
            'script'   => $scriptPresent,
            'snapshot' => $snapshotPresent,
            'script_path'   => self::BRIDGE_SCRIPT_PATH,
            'snapshot_path' => self::SNAPSHOT_PATH,
        ];
    }

    /**
     * Reads the bundled SQF template from the module's assets directory.
     */
    private function loadTemplate(): ?string
    {
        if (!is_file(self::TEMPLATE_PATH)) {
            return null;
        }

        $content = file_get_contents(self::TEMPLATE_PATH);

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * Writes an initial empty JSON snapshot only when the file does not already
     * exist in the container, so live data is never overwritten.
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
