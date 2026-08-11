<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_observed_players` (
            `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`       VARCHAR(64)   NOT NULL,
            `player_id`       VARCHAR(64)   NOT NULL,
            `player_name`     VARCHAR(255)  NOT NULL DEFAULT '',
            `last_map`        VARCHAR(64)   NOT NULL DEFAULT '',
            `last_x`          DECIMAL(10,2) NULL DEFAULT NULL,
            `last_y`          DECIMAL(10,2) NULL DEFAULT NULL,
            `last_z`          DECIMAL(10,2) NULL DEFAULT NULL,
            `last_direction`  DECIMAL(7,2)  NULL DEFAULT NULL,
            `last_alive`      TINYINT(1)    NOT NULL DEFAULT 1,
            `last_health`     DECIMAL(7,2)  NULL DEFAULT NULL,
            `inventory_json`  LONGTEXT      NULL,
            `metadata_json`   LONGTEXT      NULL,
            `first_seen_at`   TIMESTAMP     NULL DEFAULT NULL,
            `last_seen_at`    TIMESTAMP     NULL DEFAULT NULL,
            `created_at`      TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`      TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_observed_players_server_player` (`server_id`, `player_id`),
            KEY `idx_dayz_observed_players_server_seen` (`server_id`, `last_seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_observed_players`;',
    ],
];
