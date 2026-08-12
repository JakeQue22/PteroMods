<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_observed_player_backups` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`     VARCHAR(64)   NOT NULL,
            `player_id`     VARCHAR(64)   NOT NULL,
            `snapshot_json` LONGTEXT      NOT NULL,
            `created_at`    TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`    TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_dayz_observed_player_backups_server_player` (`server_id`, `player_id`),
            KEY `idx_dayz_observed_player_backups_server_created` (`server_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_observed_player_backups`;',
    ],
];
