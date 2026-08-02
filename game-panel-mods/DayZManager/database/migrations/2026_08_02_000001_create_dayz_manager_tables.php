<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS dayz_mods (id INTEGER PRIMARY KEY AUTOINCREMENT, workshop_id VARCHAR(32) NOT NULL, title VARCHAR(255) NOT NULL, folder_name VARCHAR(255) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, version VARCHAR(64) NOT NULL, latest_version VARCHAR(64) NOT NULL, dependencies TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0);',
        'CREATE TABLE IF NOT EXISTS dayz_configuration_backups (id INTEGER PRIMARY KEY AUTOINCREMENT, path VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, created_at TIMESTAMP NULL);',
    ],
    'down' => [
        'DROP TABLE IF EXISTS dayz_configuration_backups;',
        'DROP TABLE IF EXISTS dayz_mods;',
    ],
];
