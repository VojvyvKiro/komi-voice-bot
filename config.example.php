<?php
// Скопировать в config.php; секреты задать в .env для Compose.
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'fu_lab_komi');
define('DB_USER', getenv('DB_USER') ?: 'fu_lab');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');
define('VK_TOKEN', getenv('VK_TOKEN') ?: '');
define('VK_SECRET_KEY', getenv('VK_SECRET_KEY') ?: '');
define('VK_CONFIRMATION_CODE', getenv('VK_CONFIRMATION_CODE') ?: '');
define('VK_API_VERSION', '5.131');
define('ADMIN_USER', getenv('ADMIN_USER') ?: 'fulab_admin');
define('ADMIN_PASS', getenv('ADMIN_PASSWORD') ?: '');
define('BASE_URL', getenv('BASE_URL') ?: 'https://example.org');
define('UPLOAD_DIR', __DIR__ . '/uploads/recordings');
define('MIN_DURATION_SEC', 1);
define('MAX_DURATION_SEC', 60);
define('TIMEZONE', 'Europe/Moscow');
date_default_timezone_set(TIMEZONE);
