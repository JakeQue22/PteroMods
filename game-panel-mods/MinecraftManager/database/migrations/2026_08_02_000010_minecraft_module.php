<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS minecraft_module_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, key_name VARCHAR(191) NOT NULL, value_text TEXT NOT NULL);',
    ],
    'down' => [
        'DROP TABLE IF EXISTS minecraft_module_settings;',
    ],
];
