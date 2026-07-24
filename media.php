<?php
/**
 * Serve a media file from a page folder, or site branding from pages/.site/.
 * GET media.php?slug=page-slug&file=filename.png
 * GET media.php?slug=_site&file=logo.png
 */

declare(strict_types=1);

require_once __DIR__ . '/src/core/bootstrap.php';

use WikiApp\Core\DatabaseManager;
use WikiApp\Core\SiteSettings;

$slug = DatabaseManager::sanitizeSlug((string) ($_GET['slug'] ?? ''));
// Allow special branding namespace
if ((string) ($_GET['slug'] ?? '') === '_site') {
    $slug = '_site';
}

$file = (string) ($_GET['file'] ?? '');
$file = rawurldecode($file);
$file = explode('#', $file, 2)[0];
$file = explode('?', $file, 2)[0];
$file = basename(str_replace('\\', '/', $file));

$path = null;
if ($slug === '_site') {
    if ($file !== '' && !str_contains($file, '..')
        && preg_match('/^[A-Za-z0-9._-]+$/', $file)
        && $file !== 'settings.json') {
        $candidate = SiteSettings::dir() . '/' . $file;
        if (is_file($candidate)) {
            $path = $candidate;
        }
    }
} else {
    $path = DatabaseManager::resolveMediaFile($slug, $file);
}

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Media not found.';
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'pdf' => 'application/pdf',
    'css' => 'text/css; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8',
];
$mime = $types[$ext] ?? 'application/octet-stream';

// CSS overrides should revalidate quickly after admin edits
$cache = $ext === 'css' ? 'public, max-age=60, must-revalidate' : 'public, max-age=3600';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: ' . $cache);
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
