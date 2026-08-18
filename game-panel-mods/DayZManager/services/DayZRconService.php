<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\HostAddressResolver;
use Throwable;

/**
 * Sends commands over DayZ's BattlEye RCon UDP protocol.
 *
 * This deliberately does not fall back to the Wings console: commands such as
 * `say -1` are BattlEye RCon commands and are not server-process stdin.
 */
final class DayZRconService
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly DayZStartupService $startup = new DayZStartupService(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly HostAddressResolver $addresses = new HostAddressResolver(),
    ) {
    }

    public function sendCommand(mixed $server, string $command): bool
    {
        $command = trim($command);

        if ($server === null || $command === '') {
            return false;
        }

        $connection = $this->connection($server);

        if ($connection === null || !function_exists('stream_socket_client')) {
            return false;
        }

        $target = $this->udpTarget($connection['host'], $connection['port']);
        $errno = 0;
        $error = '';

        try {
            $socket = @stream_socket_client(
                $target,
                $errno,
                $error,
                self::TIMEOUT_SECONDS,
                STREAM_CLIENT_CONNECT,
            );

            if (!is_resource($socket)) {
                return false;
            }

            stream_set_timeout($socket, self::TIMEOUT_SECONDS);

            if (!$this->exchange($socket, "\xff\x00" . $connection['password'], 0)) {
                fclose($socket);

                return false;
            }

            $sent = $this->exchange($socket, "\xff\x01\x00" . $command, 1, 0);
            fclose($socket);

            return $sent;
        } catch (Throwable) {
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }

            return false;
        }
    }

    /**
     * @return array{host:string,port:int,password:string}|null
     */
    private function connection(mixed $server): ?array
    {
        $variables = $this->startup->variables($server);
        $port = (int) ($variables['RCON_PORT'] ?? 0);
        $password = trim((string) ($variables['RCON_PASSWORD'] ?? ''));

        if ($port < 1 || $port > 65535 || $password === '') {
            return null;
        }

        $allocation = $this->context->rawAttribute($server, 'allocation');
        $node = $this->context->rawAttribute($server, 'node');
        $host = $this->addresses->resolve([
            $allocation === null ? null : $this->context->attribute($allocation, ['ip_alias']),
            $allocation === null ? null : $this->context->attribute($allocation, ['ip']),
            $node === null ? null : $this->context->attribute($node, ['fqdn']),
            $variables['SERVER_IP'] ?? null,
        ]);

        return $host === '' ? null : ['host' => $host, 'port' => $port, 'password' => $password];
    }

    /**
     * Writes a BattlEye packet and validates the response type and sequence.
     * Login responses include a final byte of 1 on success.
     *
     * @param resource $socket
     */
    private function exchange($socket, string $payload, int $responseType, ?int $sequence = null): bool
    {
        $packet = 'BE' . pack('V', crc32($payload)) . $payload;

        if (@fwrite($socket, $packet) !== strlen($packet)) {
            return false;
        }

        $response = @fread($socket, 65535);

        if (!is_string($response) || strlen($response) < 9 || substr($response, 0, 2) !== 'BE') {
            return false;
        }

        $responsePayload = substr($response, 6);
        $checksum = unpack('Vcrc', substr($response, 2, 4));

        if (!is_array($checksum)
            || (int) ($checksum['crc'] ?? -1) !== (int) sprintf('%u', crc32($responsePayload))
            || ord($responsePayload[0]) !== 0xff
            || ord($responsePayload[1]) !== $responseType
        ) {
            return false;
        }

        if ($responseType === 0) {
            return strlen($responsePayload) >= 3 && ord($responsePayload[2]) === 1;
        }

        return $sequence === null
            || (strlen($responsePayload) >= 3 && ord($responsePayload[2]) === $sequence);
    }

    private function udpTarget(string $host, int $port): string
    {
        $host = trim($host, '[]');

        return 'udp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
    }
}
