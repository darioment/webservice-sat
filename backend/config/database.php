<?php

return [
    'host' => $_ENV['DB_HOST'] ?? 'sec.usoreal.com',
    'dbname' => $_ENV['DB_NAME'] ?? 'sat',
    'username' => $_ENV['DB_USERNAME'] ?? 'sat',
    'password' => $_ENV['DB_PASSWORD'] ?? 'dment25SAT!.',
    'charset' => $_ENV['DB_CHARSET'] ?? 'latin1',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]
];