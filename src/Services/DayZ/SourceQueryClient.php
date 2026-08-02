<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

use Throwable;

/**
 * Minimal Valve A2S (Source Query Protocol) client.
 *
 * DayZ servers answer A2S_INFO on their Steam query port, which is the only
 * reliable way to obtain the live map, player count, and server version.
 */
final class SourceQueryClient
{
    private const HEADER = "\xFF\xFF\xFF\xFF";
    private const A2S_INFO_PAYLOAD = "TSource Engine Query\x00";
    private const RESPONSE_INFO = 0x49;
    private const RESPONSE_CHALLENGE = 0x41;

    public function __construct(private readonly float $timeout = 1.0)
    {
    }

    /**
     * Performs an A2S_INFO query.
     *
     * @return array{name: string, map: string, players: int, max_players: int, version: string, game: string}|null
     *         Null when the server is unreachable or the response is malformed.
     */
    public function info(string $host, int $port): ?array
    {
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $socket = @fsockopen('udp://' . $host, $port, $errorCode, $errorMessage, $this->timeout);

        if ($socket === false) {
            return null;
        }

        try {
            stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1.0) * 1_000_000));
            stream_set_blocking($socket, true);

            $response = $this->exchange($socket, self::HEADER . "\x54" . self::A2S_INFO_PAYLOAD);

            if ($response === null) {
                return null;
            }

            // Newer servers reply with a challenge that must be echoed back.
            if ($this->packetType($response) === self::RESPONSE_CHALLENGE) {
                $challenge = substr($response, 5, 4);
                $response = $this->exchange($socket, self::HEADER . "\x54" . self::A2S_INFO_PAYLOAD . $challenge);

                if ($response === null) {
                    return null;
                }
            }

            if ($this->packetType($response) !== self::RESPONSE_INFO) {
                return null;
            }

            return $this->parseInfo($response);
        } catch (Throwable) {
            return null;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function exchange($socket, string $request): ?string
    {
        if (@fwrite($socket, $request) === false) {
            return null;
        }

        $response = @fread($socket, 4096);

        if (!is_string($response) || strlen($response) < 5 || !str_starts_with($response, self::HEADER)) {
            return null;
        }

        return $response;
    }

    private function packetType(string $response): int
    {
        return ord($response[4]);
    }

    /**
     * @return array{name: string, map: string, players: int, max_players: int, version: string, game: string}|null
     */
    private function parseInfo(string $response): ?array
    {
        // Skip the 4-byte header, the payload type byte, and the protocol byte.
        $offset = 6;

        $name = $this->readString($response, $offset);
        $map = $this->readString($response, $offset);
        $this->readString($response, $offset); // folder
        $game = $this->readString($response, $offset);

        if ($name === null || $map === null || $game === null) {
            return null;
        }

        $offset += 2; // app id (short)

        if (!isset($response[$offset], $response[$offset + 1])) {
            return null;
        }

        $players = ord($response[$offset]);
        $maxPlayers = ord($response[$offset + 1]);
        $offset += 3; // players, max players, bots

        $offset += 3; // server type, environment, visibility
        $offset += 1; // VAC

        $version = $this->readString($response, $offset) ?? '';

        return [
            'name'        => $name,
            'map'         => $map,
            'players'     => $players,
            'max_players' => $maxPlayers,
            'version'     => $version,
            'game'        => $game,
        ];
    }

    private function readString(string $response, int &$offset): ?string
    {
        $end = strpos($response, "\x00", $offset);

        if ($end === false) {
            return null;
        }

        $value = substr($response, $offset, $end - $offset);
        $offset = $end + 1;

        return $value;
    }
}
