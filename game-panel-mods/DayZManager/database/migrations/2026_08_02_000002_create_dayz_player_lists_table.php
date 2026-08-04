<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_player_lists` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `list_type`  ENUM('ban','whitelist','priority') NOT NULL,
            `player_id`  VARCHAR(64)   NOT NULL COMMENT 'Steam64 ID or GUID',
            `note`       VARCHAR(255)  NOT NULL DEFAULT '',
            `added_by`   VARCHAR(64)   NOT NULL DEFAULT '',
            `created_at` TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_player_lists_type_player` (`list_type`, `player_id`),
            KEY `idx_dayz_player_lists_type` (`list_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_player_lists`;',
    ],
];
