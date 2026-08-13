<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_player_nicknames` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`   VARCHAR(64)   NOT NULL,
            `player_id`   VARCHAR(64)   NOT NULL,
            `nickname`    VARCHAR(255)  NOT NULL DEFAULT '',
            `first_seen_at` TIMESTAMP   NULL DEFAULT NULL,
            `last_seen_at`  TIMESTAMP   NULL DEFAULT NULL,
            `created_at`  TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`  TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_player_nicknames_server_player_nick` (`server_id`, `player_id`, `nickname`(191)),
            KEY `idx_dayz_player_nicknames_server_player` (`server_id`, `player_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_player_nicknames`;',
    ],
];
