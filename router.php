<?php
/**
 * Router for PHP's built-in development server:
 *   php -S localhost:8080 router.php
 *
 * Serves static files as-is; everything else goes to index.php (with path→slug mapping).
 */

declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$file = __DIR__ . $uri;

// Serve real files (assets, admin scripts, etc.)
if ($uri !== '/' && is_file($file)) {
    return false;
}

// Missing asset paths must 404 as plain failures, not HTML pages.
if (str_starts_with($uri, '/assets/')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found: ' . $uri;
    return true;
}

// Map /admin and /admin/ to admin/index.php
if ($uri === '/admin' || $uri === '/admin/') {
    require __DIR__ . '/admin/index.php';
    return true;
}

// Clean public URLs: /some-slug → index.php?slug=some-slug
if (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
