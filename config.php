<?php
define('JWT_SECRET', 'your-secret-key-change-this-in-production');   // TODO: change to a secure secret
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRY', 3600); // 1 hour
define('DATA_DIR', __DIR__ . '/data');   // TODO: change to your data directory


// User credentials (in production, use a database)
$validUsers = [
    'admin' => password_hash('secret123', PASSWORD_DEFAULT)
];
