<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_backups` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`   VARCHAR(64)   NOT NULL,
            `label`       VARCHAR(255)  NOT NULL DEFAULT '',
            `trigger`     VARCHAR(32)   NOT NULL DEFAULT 'manual' COMMENT 'manual or auto',
            `payload`     LONGTEXT      NOT NULL,
            `created_at`  TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_dayz_backups_server` (`server_id`),
            KEY `idx_dayz_backups_created` (`server_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_backups`;',
    ],
];
