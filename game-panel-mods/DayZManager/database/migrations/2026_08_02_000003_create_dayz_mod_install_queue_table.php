<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_mod_install_queue` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`    VARCHAR(64)   NOT NULL,
            `workshop_id`  VARCHAR(32)   NOT NULL,
            `title`        VARCHAR(255)  NOT NULL DEFAULT '',
            `thumbnail`    VARCHAR(500)  NOT NULL DEFAULT '',
            `file_size`    VARCHAR(32)   NOT NULL DEFAULT '',
            `status`       VARCHAR(16)   NOT NULL DEFAULT 'queued',
            `position`     INT           NOT NULL DEFAULT 0,
            `created_at`   TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`   TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_mod_install_queue_server_workshop` (`server_id`, `workshop_id`),
            KEY `idx_dayz_mod_install_queue_server` (`server_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_mod_install_queue`;',
    ],
];
