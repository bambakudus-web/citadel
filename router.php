<?php
// Railway router — serves static files directly, routes PHP requests
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// Serve static files directly without PHP processing
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimes = [
        'js'    => 'application/javascript',
        'css'   => 'text/css',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'json'  => 'application/json',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'map'   => 'application/json',
    ];
    if (isset($mimes[$ext])) {
        // Cache static assets
        header('Content-Type: ' . $mimes[$ext]);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        return true;
    }
}

// Route PHP files
return false;
