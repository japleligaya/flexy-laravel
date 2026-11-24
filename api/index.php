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

require __DIR__.'/../public/index.php';
