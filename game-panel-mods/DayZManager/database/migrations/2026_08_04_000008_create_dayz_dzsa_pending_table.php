<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_dzsa_pending` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`    VARCHAR(64)   NOT NULL,
            `status`       VARCHAR(16)   NOT NULL DEFAULT 'waiting',
            `created_at`   TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`   TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_dzsa_pending_server` (`server_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_dzsa_pending`;',
    ],
];
