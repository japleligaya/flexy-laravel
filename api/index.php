<?php

$storage = sys_get_temp_dir().'/flexy-laravel';

foreach (['app/private', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs'] as $directory) {
    $path = $storage.'/'.$directory;

    if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
        error_log('Could not initialize Laravel temporary storage.');
        http_response_code(503);
        return;
    }
}

$_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
$_SERVER['FLEXY_VERCEL_ENTRYPOINT'] = true;

if ($certificate = getenv('MYSQL_SSL_CA_PEM')) {
    $caPath = $storage.'/mysql-ca-'.hash('sha256', $certificate).'.pem';

    if (! is_file($caPath) && file_put_contents($caPath, $certificate, LOCK_EX) === false) {
        error_log('Could not initialize the MySQL CA certificate.');
        http_response_code(503);
        return;
    }

    $_SERVER['MYSQL_ATTR_SSL_CA'] = $caPath;
}

require __DIR__.'/../public/index.php';
