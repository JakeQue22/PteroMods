<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_removed_players` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`   VARCHAR(64)   NOT NULL,
            `player_id`   VARCHAR(64)   NOT NULL,
            `player_name` VARCHAR(255)  NOT NULL DEFAULT '',
            `removed_by`  VARCHAR(255)  NOT NULL DEFAULT '',
            `created_at`  TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`  TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_removed_players_server_player` (`server_id`, `player_id`),
            KEY `idx_dayz_removed_players_server` (`server_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_removed_players`;',
    ],
];
