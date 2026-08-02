<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\LaunchParameterBuilder;

/**
 * Server-level controls: power actions and the live launch parameters.
 */
final class DayZServerService
{
    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStartupService $startup = new DayZStartupService(),
    ) {
    }

    /**
     * Sends a power signal to the server through the Pterodactyl daemon.
     *
     * @return array<string, mixed>
     */
    public function power(mixed $server, string $signal, string $reason = ''): array
    {
        $signal = strtolower(trim($signal));

        if (!in_array($signal, ['start', 'stop', 'restart', 'kill'], true)) {
            return [
                'status'  => 'rejected',
                'action'  => $signal,
                'message' => 'Unsupported power signal.',
            ];
        }

        $dispatched = $this->gateway->power($server, $signal);

        return [
            'status'    => $dispatched ? 'dispatched' : 'failed',
            'action'    => $signal,
            'reason'    => $reason,
            'queued_at' => date('c'),
            'message'   => $dispatched
                ? 'Power signal sent to the daemon.'
                : 'The daemon did not accept the power signal.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function restart(string $reason = '', mixed $server = null): array
    {
        return $this->power($server, 'restart', $reason);
    }

    /**
     * The launch parameters Pterodactyl actually starts the server with.
     *
     * @param list<string> $enabledFolders Ordered folder names of enabled mods.
     * @return array<string, mixed>
     */
    public function launchParameters(mixed $server = null, array $enabledFolders = []): array
    {
        $startup = $this->startup->startup($server);
        $modFolders = $startup['mods'] !== [] ? $startup['mods'] : $enabledFolders;

        return [
            'launch_parameters'  => (new LaunchParameterBuilder())->build($modFolders),
            'mod_count'          => count(array_filter($modFolders, static fn (string $f): bool => $f !== '')),
            'startup_raw'        => $startup['raw'],
            'startup_rendered'   => $startup['rendered'],
            'startup_parameters' => $startup['parameters'],
            'startup_variables'  => $startup['variables'],
            'startup_source'     => $startup['source'],
            'mods'               => $startup['mods'],
            'server_mods'        => $startup['server_mods'],
        ];
    }
}
