<?php
// Router for PHP's built-in server (replaces Apache + .htaccess).
// Usage: php -S localhost:8000 -t public router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// API requests -> front controller (same job as the .htaccess rewrite rule)
if (str_starts_with($path, '/api/') || $path === '/api') {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require __DIR__ . '/public/api/index.php';
    return true;
}

// Block direct access to non-public folders
if (preg_match('#^/(app|storage|tests|\.git)(/|$)#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Default page
if ($path === '/') {
    header('Location: /CCLog-in.html');
    return true;
}

// Everything else: let the built-in server serve the static file from public/
return false;
