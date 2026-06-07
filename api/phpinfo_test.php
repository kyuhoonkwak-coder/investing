<?php
echo json_encode([
    'php_version' => PHP_VERSION,
    'php_major'   => PHP_MAJOR_VERSION,
    'openssl'     => extension_loaded('openssl'),
    'curl'        => extension_loaded('curl'),
    'allow_url_fopen' => ini_get('allow_url_fopen'),
    'config_exists'   => file_exists(__DIR__ . '/config.php'),
]);
