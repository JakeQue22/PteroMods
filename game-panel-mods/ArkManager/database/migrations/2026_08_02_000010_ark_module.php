<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS ark_module_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, key_name VARCHAR(191) NOT NULL, value_text TEXT NOT NULL);',
    ],
    'down' => [
        'DROP TABLE IF EXISTS ark_module_settings;',
    ],
];
