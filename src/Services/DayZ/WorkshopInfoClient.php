<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

use Throwable;

/**
 * Looks up a Workshop item's public details (title, thumbnail, size) from the
 * Steam Web API, so an operator sees what a Workshop ID actually is as soon
 * as they enter it, instead of a bare number.
 *
 * The `GetPublishedFileDetails` endpoint requires no API key, so this works
 * out of the box; it degrades to `null` when the panel has no outbound
 * internet access or the item does not exist.
 */
final class WorkshopInfoClient
{
    private const ENDPOINT = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';

    /**
     * @return array{title: string, thumbnail: string, file_size: int, time_updated: int, description: string}|null
     */
    public function fetch(string $workshopId): ?array
    {
        if ($workshopId === '' || !ctype_digit($workshopId) || !class_exists('Illuminate\\Support\\Facades\\Http')) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::asForm()
                ->timeout(5)
                ->post(self::ENDPOINT, [
                    'itemcount'           => 1,
                    'publishedfileids[0]' => $workshopId,
                ]);

            if (!$response->successful()) {
                return null;
            }

            $details = $response->json('response.publishedfiledetails.0');
        } catch (Throwable) {
            return null;
        }

        if (!is_array($details) || (int) ($details['result'] ?? 0) !== 1) {
            return null;
        }

        return [
            'title'        => (string) ($details['title'] ?? ''),
            'thumbnail'    => (string) ($details['preview_url'] ?? ''),
            'file_size'    => (int) ($details['file_size'] ?? 0),
            'time_updated' => (int) ($details['time_updated'] ?? 0),
            'description'  => (string) ($details['description'] ?? ''),
        ];
    }
}
