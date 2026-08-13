<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_give_money_queue` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`   VARCHAR(64)   NOT NULL,
            `player_id`   VARCHAR(64)   NOT NULL,
            `player_name` VARCHAR(255)  NOT NULL DEFAULT '',
            `item_class`  VARCHAR(64)   NOT NULL,
            `quantity`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `status`      VARCHAR(16)   NOT NULL DEFAULT 'pending',
            `note`        VARCHAR(255)  NOT NULL DEFAULT '',
            `created_at`  TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`  TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_dayz_give_money_queue_server_player` (`server_id`, `player_id`),
            KEY `idx_dayz_give_money_queue_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_give_money_queue`;',
    ],
];
