<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_manager_settings` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `key`        VARCHAR(100)  NOT NULL,
            `value`      TEXT          NOT NULL DEFAULT '',
            `created_at` TIMESTAMP     NULL DEFAULT NULL,
            `updated_at` TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_manager_settings_key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_manager_settings`;',
    ],
];
