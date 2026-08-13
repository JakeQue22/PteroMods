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
    private const MODLIST_PATH = '/modlist.html';

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly StartupCommandRenderer $renderer = new StartupCommandRenderer(),
        private readonly ModMetaParser $meta = new ModMetaParser(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
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
     * Appends new Workshop IDs to the egg variable that the startup script
     * reads for its SteamCMD mod-download step (e.g. `MODS`, `MOD_LIST`).
     *
     * Only variables whose current value already consists entirely of
     * Workshop IDs (numeric tokens) are updated, to avoid corrupting
     * variables that store folder names instead of IDs. If no suitable
     * variable is found the call is a no-op.
     *
     * @param list<string> $workshopIds
     */
    public function appendWorkshopIds(mixed $server, array $workshopIds): void
    {
        $workshopIds = $this->normalizeWorkshopIds($workshopIds);

        if ($workshopIds === []) {
            return;
        }

        $variables = $this->databaseVariables($server);
        $startupModVariable = $this->startupModVariableName($server);

        foreach (self::MOD_LIST_VARIABLES as $name) {
            if (!array_key_exists($name, $variables)) {
                continue;
            }

            if ($startupModVariable !== null && strtoupper($name) === $startupModVariable) {
                continue;
            }

            $current = trim($variables[$name]);
            $tokens = $current === '' ? [] : array_values(array_filter(
                preg_split('/[;,\s]+/', $current) ?: [],
                static fn (string $t): bool => $t !== '',
            ));

            // Only touch the variable when it is empty or already contains
            // numeric Workshop IDs (not folder names like @CF).
            $allNumeric = $tokens === [] || !in_array(false, array_map('ctype_digit', $tokens), true);

            if (!$allNumeric) {
                continue;
            }

            $merged = array_values(array_unique(array_merge($tokens, $workshopIds)));
            $this->writeServerVariable($server, $name, implode(';', $merged));
            $this->syncModlistHtml($server, $merged);

            return;
        }

        $this->syncModlistHtml($server, $workshopIds);
    }

    /**
     * Removes Workshop IDs from startup variables used for SteamCMD downloads.
     *
     * Variables containing only numeric IDs are filtered directly. Variables
     * whose values were later rewritten with folder names (e.g. `@CF;@Mod`)
     * are also handled: any token whose bare value (after stripping a leading
     * `@`) matches one of the Workshop IDs to remove is dropped, so a removal
     * always sticks even when `saveModList` already rewrote the variable.
     *
     * @param list<string> $workshopIds
     * @param list<string> $remainingIds
     */
    public function removeWorkshopIds(mixed $server, array $workshopIds, array $remainingIds = []): void
    {
        $workshopIds = $this->normalizeWorkshopIds($workshopIds);
        $remainingIds = $this->normalizeWorkshopIds($remainingIds);

        if ($workshopIds === []) {
            $this->syncModlistHtml($server, $remainingIds);

            return;
        }

        $variables = $this->databaseVariables($server);

        foreach (self::MOD_LIST_VARIABLES as $name) {
            if (!array_key_exists($name, $variables)) {
                continue;
            }

            $current = trim($variables[$name]);
            $tokens = $current === '' ? [] : array_values(array_filter(
                preg_split('/[;,\s]+/', $current) ?: [],
                static fn (string $t): bool => $t !== '',
            ));

            // Strip a leading `@` before comparing so that workshop IDs stored
            // as folder names (`@1797720064`) are matched the same way as the
            // plain numeric form (`1797720064`).
            $filtered = array_values(array_filter(
                $tokens,
                static function (string $token) use ($workshopIds): bool {
                    $bare = ltrim(trim($token), '@');

                    return !in_array($bare, $workshopIds, true);
                },
            ));

            if ($filtered !== $tokens) {
                $this->writeServerVariable($server, $name, implode(';', $filtered));
            }
        }

        $this->syncModlistHtml($server, $remainingIds);
    }

    /**
     * Writes a DayZ Launcher-style modlist file consumed by popular DayZ eggs.
     *
     * @param list<string> $workshopIds
     */
    public function syncModlistHtml(mixed $server, array $workshopIds): bool
    {
        $workshopIds = $this->normalizeWorkshopIds($workshopIds);

        return $this->gateway->writeFile($server, self::MODLIST_PATH, $this->buildModlistHtml($server, $workshopIds));
    }

    /**
     * Persists a new mod load order for a server.
     *
     * The list is written to the egg variable the startup command uses for
     * `-mod=` when there is one, because that is what the panel sends to Wings
     * on the next boot. Servers whose startup command contains a literal list
     * get the command itself rewritten instead.
     *
     * @param list<string> $folders
     * @return array{saved: bool, target: string, message: string}
     */
    public function saveModList(mixed $server, array $folders, string $parameter = 'mod'): array
    {
        $folders = array_values(array_filter(array_map(
            static fn (mixed $folder): string => trim((string) $folder),
            $folders,
        ), static fn (string $folder): bool => $folder !== ''));

        $value = implode(';', $folders);
        $raw = $this->rawStartup($server);
        $serverId = (int) ($this->context->rawAttribute($server, 'id') ?? 0);

        if ($serverId <= 0 || $raw === '') {
            return ['saved' => false, 'target' => 'none', 'message' => 'The startup command for this server is not available.'];
        }

        $current = $this->rawParameterValue($raw, $parameter);
        $variable = $current === null ? null : $this->placeholderName($current);

        if ($variable !== null && $this->writeServerVariable($server, $variable, $value)) {
            return ['saved' => true, 'target' => 'variable:' . $variable, 'message' => sprintf('Saved the load order to the %s startup variable.', $variable)];
        }

        if ($this->writeStartupCommand($serverId, $raw, $parameter, $value)) {
            return ['saved' => true, 'target' => 'startup', 'message' => 'Saved the load order to the startup command.'];
        }

        return ['saved' => false, 'target' => 'none', 'message' => 'The load order could not be written back to Pterodactyl.'];
    }

    /**
     * The unexpanded value of a startup parameter, for example `{{MOD_LIST}}`.
     */
    private function rawParameterValue(string $raw, string $parameter): ?string
    {
        $pattern = '/-' . preg_quote($parameter, '/') . '=("[^"]*"|\'[^\']*\'|[^\s"\']*)/i';

        if (preg_match($pattern, $raw, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], "\"'");
    }

    /**
     * The variable name when a value is a single `{{VARIABLE}}` placeholder.
     */
    private function placeholderName(string $value): ?string
    {
        if (preg_match('/^\{\{\s*(?:env\.)?([A-Za-z0-9_]+)\s*\}\}$/', trim($value), $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function startupModVariableName(mixed $server): ?string
    {
        $raw = $this->rawStartup($server);
        $value = $this->rawParameterValue($raw, 'mod');

        return $value === null ? null : $this->placeholderName($value);
    }

    /**
     * Writes a value to `server_variables`, creating the row when needed.
     */
    private function writeServerVariable(mixed $server, string $variable, string $value): bool
    {
        $serverId = (int) ($this->context->rawAttribute($server, 'id') ?? 0);
        $eggId = (int) ($this->context->rawAttribute($server, 'egg_id') ?? 0);

        if ($serverId <= 0 || $eggId <= 0 || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return false;
        }

        try {
            $variableId = \Illuminate\Support\Facades\DB::table('egg_variables')
                ->where('egg_id', $eggId)
                ->whereRaw('UPPER(env_variable) = ?', [$variable])
                ->value('id');

            if ($variableId === null) {
                return false;
            }

            \Illuminate\Support\Facades\DB::table('server_variables')->updateOrInsert(
                ['server_id' => $serverId, 'variable_id' => (int) $variableId],
                ['variable_value' => $value],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Rewrites the `-mod=` parameter inside the stored startup command.
     */
    private function writeStartupCommand(int $serverId, string $raw, string $parameter, string $value): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\DB')) {
            return false;
        }

        $replacement = '-' . $parameter . '="' . $value . '"';
        $pattern = '/"?-' . preg_quote($parameter, '/') . '=("[^"]*"|\'[^\']*\'|[^\s"\']*)"?/i';

        if (preg_match($pattern, $raw) === 1) {
            $startup = (string) preg_replace($pattern, $replacement, $raw, 1);
        } elseif ($value === '') {
            return true;
        } else {
            $startup = rtrim($raw) . ' ' . $replacement;
        }

        try {
            \Illuminate\Support\Facades\DB::table('servers')
                ->where('id', $serverId)
                ->update(['startup' => $startup]);

            return true;
        } catch (Throwable) {
            return false;
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

    /**
     * @param list<string> $workshopIds
     * @return list<string>
     */
    private function normalizeWorkshopIds(array $workshopIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $workshopIds,
        ), static fn (string $id): bool => $id !== '' && ctype_digit($id) && (int) $id > 0)));
    }

    /**
     * @param list<string> $workshopIds
     */
    private function buildModlistHtml(mixed $server, array $workshopIds): string
    {
        $lines = [
            '<!-- Created by DayZ Launcher -->',
            '<html>',
            '<body>',
        ];

        foreach ($workshopIds as $workshopId) {
            $url = 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . rawurlencode($workshopId);
            $label = $this->modlistLabel($server, $workshopId);
            $lines[] = sprintf('<a href="%s">%s</a><br />', $url, htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $lines[] = '</body>';
        $lines[] = '</html>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Resolves the friendly title for a Workshop ID from the mod folder's own
     * `meta.cpp`/`mod.cpp`, without depending on `DayZWorkshopService` (which
     * itself depends on this class), to avoid a circular dependency.
     */
    private function modlistLabel(mixed $server, string $workshopId): string
    {
        foreach ($this->gateway->listDirectory($server, '/') as $entry) {
            $name = $entry['name'] ?? '';

            if ($name === '' || !str_starts_with($name, '@') || ($entry['file'] ?? false)) {
                continue;
            }

            $metadata = [];

            foreach (['meta.cpp', 'mod.cpp'] as $file) {
                $contents = $this->gateway->readFile($server, '/' . $name . '/' . $file);

                if ($contents === null || $contents === '') {
                    continue;
                }

                $metadata += $this->meta->parse($contents);
            }

            if ((string) ($metadata['publishedid'] ?? '') !== $workshopId) {
                continue;
            }

            $title = trim((string) ($metadata['name'] ?? ''));

            if ($title !== '' && strcasecmp($title, $workshopId) !== 0) {
                return $title;
            }

            break;
        }

        return $workshopId;
    }
}
