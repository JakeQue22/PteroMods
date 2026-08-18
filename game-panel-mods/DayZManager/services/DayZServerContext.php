<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Resolves the Pterodactyl server behind a module route parameter.
 *
 * Every DayZ Manager route receives the server identifier used by the panel
 * client area (the short UUID). This helper turns that value into a server
 * model when the panel is available, and degrades gracefully otherwise.
 */
final class DayZServerContext
{
    /**
     * Resolves a route parameter into an identifier, display name, and model.
     *
     * @return array{id: string, name: string, model: mixed}
     */
    public function resolve(mixed $server = null): array
    {
        $model = null;
        $identifier = '';

        if (is_object($server) || is_array($server)) {
            $model = $server;
            $identifier = $this->identifierFrom($server);
        } elseif (is_string($server) && $server !== '') {
            $identifier = $server;
        } else {
            $identifier = $this->routeServerParameter();
        }

        if ($model === null && $identifier !== '') {
            $model = $this->findServer($identifier);
        }

        if ($model !== null && $identifier === '') {
            $identifier = $this->identifierFrom($model);
        }

        $name = $this->attribute($model, ['name', 'server_name']);

        $this->authorize($model);

        return [
            'id'    => $identifier,
            'name'  => $name !== '' ? $name : ($identifier !== '' ? $identifier : 'DayZ Server'),
            'model' => $model,
        ];
    }

    /**
     * The identifier the panel client area uses in its URLs (the short UUID),
     * falling back to the identifier the route was called with.
     */
    public function clientIdentifier(mixed $model, string $fallback = ''): string
    {
        $identifier = $this->attribute($model, ['uuidShort', 'uuid_short']);

        return $identifier !== '' ? $identifier : $fallback;
    }

    /**
     * Aborts unless the authenticated user may change server settings.
     *
     * Read-only pages are available to subusers, but everything that rewrites
     * the mod load order or the startup command is limited to administrators
     * and the server owner, mirroring the panel's own startup permissions.
     */
    public function authorizeManage(mixed $model): void
    {
        if (!function_exists('abort') || !class_exists('Illuminate\\Support\\Facades\\Auth')) {
            return;
        }

        try {
            $user = \Illuminate\Support\Facades\Auth::user();
        } catch (Throwable) {
            abort(403);
        }

        if ($user === null) {
            abort(403);
        }

        if ((bool) ($this->rawAttribute($user, 'root_admin') ?? false)) {
            return;
        }

        $userId = (int) ($this->rawAttribute($user, 'id') ?? 0);
        $ownerId = (int) ($this->rawAttribute($model, 'owner_id') ?? 0);

        if ($userId <= 0 || $userId !== $ownerId) {
            abort(403);
        }
    }

    /**
     * Reads the `server` route parameter as a string when a request is available.
     */
    public function routeServerParameter(): string
    {
        if (!function_exists('request')) {
            return '';
        }

        try {
            $request = request();

            if (!is_object($request) || !method_exists($request, 'route')) {
                return '';
            }

            $routeServer = $request->route('server');

            if (is_string($routeServer)) {
                return $routeServer;
            }

            if (is_object($routeServer)) {
                return $this->identifierFrom($routeServer);
            }
        } catch (Throwable) {
            return '';
        }

        return '';
    }

    /**
     * Reads an input value from the current request, if any.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (!function_exists('request')) {
            return $default;
        }

        try {
            $request = request();

            if (!is_object($request) || !method_exists($request, 'input')) {
                return $default;
            }

            $value = $request->input($key);

            return $value === null ? $default : $value;
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Reads a string input value from the current request.
     */
    public function stringInput(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * True when the current request expects a JSON payload (API routes).
     */
    public function expectsJson(): bool
    {
        if (!function_exists('request')) {
            return false;
        }

        try {
            $request = request();

            if (!is_object($request)) {
                return false;
            }

            if (method_exists($request, 'is') && $request->is('api/*')) {
                return true;
            }

            return method_exists($request, 'expectsJson') && (bool) $request->expectsJson();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Reads a string attribute from an array or model, returning '' when absent.
     *
     * @param list<string> $keys
     */
    public function attribute(mixed $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->rawAttribute($source, $key);

            if (is_scalar($value)) {
                $string = trim((string) $value);

                if ($string !== '') {
                    return $string;
                }
            }
        }

        return '';
    }

    public function rawAttribute(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        if (!is_object($source)) {
            return null;
        }

        try {
            if (method_exists($source, 'getAttribute')) {
                $attribute = $source->getAttribute($key);

                if ($attribute !== null) {
                    return $attribute;
                }
            }

            if (isset($source->{$key})) {
                return $source->{$key};
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function identifierFrom(mixed $server): string
    {
        return $this->attribute($server, ['uuidShort', 'uuid_short', 'uuid', 'id']);
    }

    /**
     * Aborts the request when the authenticated user may not access the server.
     *
     * The panel's own server-access middleware relies on route model binding,
     * which module routes do not use, so ownership is verified here instead:
     * administrators, the owner, and subusers are allowed through.
     */
    private function authorize(mixed $model): void
    {
        if (!function_exists('abort')
            || !class_exists('Illuminate\\Support\\Facades\\Auth')) {
            return;
        }

        try {
            $user = \Illuminate\Support\Facades\Auth::user();
        } catch (Throwable) {
            abort(403);
        }

        if ($user === null) {
            abort(403);
        }

        if ((bool) ($this->rawAttribute($user, 'root_admin') ?? false)) {
            return;
        }

        if ($model === null) {
            abort(403);
        }

        $userId = (int) ($this->rawAttribute($user, 'id') ?? 0);
        $ownerId = (int) ($this->rawAttribute($model, 'owner_id') ?? 0);

        if ($userId > 0 && $userId === $ownerId) {
            return;
        }

        if (!$this->isSubuser($model, $userId)) {
            abort(403);
        }
    }

    private function isSubuser(mixed $model, int $userId): bool
    {
        $serverId = (int) ($this->rawAttribute($model, 'id') ?? 0);

        if ($userId <= 0
            || $serverId <= 0
            || !class_exists('Illuminate\\Support\\Facades\\DB')
            || !class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('subusers')) {
                return false;
            }

            return \Illuminate\Support\Facades\DB::table('subusers')
                ->where('server_id', $serverId)
                ->where('user_id', $userId)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function findServer(string $identifier): mixed
    {
        if (!class_exists('Pterodactyl\\Models\\Server')) {
            return null;
        }

        try {
            return \Pterodactyl\Models\Server::query()
                ->where('uuidShort', $identifier)
                ->orWhere('uuid', $identifier)
                ->orWhere('id', ctype_digit($identifier) ? (int) $identifier : 0)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }
}
