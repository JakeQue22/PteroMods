<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_server_mod_order` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`   VARCHAR(64)   NOT NULL,
            `folder_name` VARCHAR(255)  NOT NULL,
            `position`    INT           NOT NULL DEFAULT 0,
            `enabled`     TINYINT(1)    NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_server_mod_order` (`server_id`, `folder_name`),
            KEY `idx_dayz_server_mod_order_server` (`server_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_server_mod_order`;',
    ],
];
