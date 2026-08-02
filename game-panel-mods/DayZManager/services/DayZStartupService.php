<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\ModMetaParser;
use PteroMods\Services\DayZ\StartupCommandRenderer;
use Throwable;

/**
 * Reads the startup command and egg variables Pterodactyl actually boots a
 * server with, and derives the DayZ mod load order from them.
 *
 * The panel stores the startup command with `{{VARIABLE}}` placeholders on the
 * server (or, when it was never overridden, on the egg), and the values in
 * `server_variables`. Both are combined here so the module always shows the
 * real launch parameters instead of a reconstruction.
 */
final class DayZStartupService
{
    /** Egg variables commonly used by DayZ eggs to hold the client mod list. */
    private const MOD_LIST_VARIABLES = ['MOD_LIST', 'MODS', 'MODLIST', 'CLIENT_MODS', 'MOD'];

    /** Egg variables commonly used by DayZ eggs to hold the server-only mod list. */
    private const SERVER_MOD_LIST_VARIABLES = ['SERVER_MOD_LIST', 'SERVER_MODS', 'SERVERMODS', 'SERVER_MOD'];

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly StartupCommandRenderer $renderer = new StartupCommandRenderer(),
        private readonly ModMetaParser $meta = new ModMetaParser(),
    ) {
    }

    /**
     * Full startup information for a server.
     *
     * @return array{raw: string, rendered: string, parameters: list<string>, variables: array<string, string>, mods: list<string>, server_mods: list<string>, source: string}
     */
    public function startup(mixed $server): array
    {
        $raw = $this->rawStartup($server);
        $variables = $this->variables($server);
        $rendered = $this->renderer->render($raw, $variables);

        return [
            'raw'         => $raw,
            'rendered'    => $rendered,
            'parameters'  => $this->renderer->parameters($rendered),
            'variables'   => $variables,
            'mods'        => $this->modFolders($rendered, $variables, self::MOD_LIST_VARIABLES, 'mod'),
            'server_mods' => $this->modFolders($rendered, $variables, self::SERVER_MOD_LIST_VARIABLES, 'serverMod'),
            'source'      => $raw === '' ? 'unavailable' : 'pterodactyl',
        ];
    }

    /**
     * The startup command stored on the server, falling back to its egg.
     */
    public function rawStartup(mixed $server): string
    {
        $startup = $this->context->attribute($server, ['startup']);

        if ($startup !== '') {
            return $startup;
        }

        $egg = $this->context->rawAttribute($server, 'egg');

        return $egg === null ? '' : $this->context->attribute($egg, ['startup']);
    }

    /**
     * Environment variables for a server: egg defaults overridden by the values
     * configured on the server, plus the panel-provided runtime variables.
     *
     * @return array<string, string>
     */
    public function variables(mixed $server): array
    {
        $variables = $this->databaseVariables($server);

        $memory = $this->context->rawAttribute($server, 'memory');
        $allocation = $this->context->rawAttribute($server, 'allocation');

        $runtime = [
            'SERVER_MEMORY' => $memory === null ? '' : (string) $memory,
            'SERVER_IP'     => $allocation === null ? '' : $this->context->attribute($allocation, ['ip_alias', 'ip']),
            'SERVER_PORT'   => $allocation === null ? '' : $this->context->attribute($allocation, ['port']),
            'P_SERVER_UUID' => $this->context->attribute($server, ['uuid']),
        ];

        foreach ($runtime as $key => $value) {
            if ($value !== '' && !array_key_exists($key, $variables)) {
                $variables[$key] = $value;
            }
        }

        return $variables;
    }

    /**
     * @return array<string, string>
     */
    private function databaseVariables(mixed $server): array
    {
        $serverId = (int) ($this->context->rawAttribute($server, 'id') ?? 0);
        $eggId = (int) ($this->context->rawAttribute($server, 'egg_id') ?? 0);

        if ($serverId <= 0
            || !class_exists('Illuminate\\Support\\Facades\\DB')
            || !class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return [];
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('server_variables')
                || !\Illuminate\Support\Facades\Schema::hasTable('egg_variables')) {
                return [];
            }

            $variables = [];

            if ($eggId > 0) {
                $defaults = \Illuminate\Support\Facades\DB::table('egg_variables')
                    ->where('egg_id', $eggId)
                    ->get(['env_variable', 'default_value']);

                foreach ($defaults as $default) {
                    $default = (array) $default;
                    $variables[strtoupper((string) ($default['env_variable'] ?? ''))] = (string) ($default['default_value'] ?? '');
                }
            }

            $configured = \Illuminate\Support\Facades\DB::table('server_variables')
                ->join('egg_variables', 'egg_variables.id', '=', 'server_variables.variable_id')
                ->where('server_variables.server_id', $serverId)
                ->get(['egg_variables.env_variable', 'server_variables.variable_value']);

            foreach ($configured as $variable) {
                $variable = (array) $variable;
                $variables[strtoupper((string) ($variable['env_variable'] ?? ''))] = (string) ($variable['variable_value'] ?? '');
            }

            unset($variables['']);

            return $variables;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Mod folders taken from the rendered startup command, falling back to the
     * egg variable that holds the list.
     *
     * @param array<string, string> $variables
     * @param list<string>          $variableNames
     * @return list<string>
     */
    private function modFolders(string $rendered, array $variables, array $variableNames, string $parameter): array
    {
        $folders = $this->meta->parseModList($rendered, $parameter);

        if ($folders !== []) {
            return $folders;
        }

        foreach ($variableNames as $name) {
            $value = $variables[$name] ?? '';

            if (trim($value) !== '') {
                $folders = $this->meta->parseModList($value, $parameter);

                if ($folders !== []) {
                    return $folders;
                }
            }
        }

        return [];
    }
}
