<?php
/**
 * Shared bootstrap: constants, autoload, and request helpers.
 */

declare(strict_types=1);

namespace WikiApp\Core;

// Absolute filesystem root of the wikiFlip app (folder containing index.php)
define('WIKIFLIP_ROOT', dirname(__DIR__, 2));

require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/db_manager.php';
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/content_backup.php';
require_once __DIR__ . '/auth.php';

/**
 * Detect the web base path when the app is not at domain root
 * (e.g. /wikiFlip). Empty string when served from document root.
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // /admin/edit.php → /admin, /index.php → ''
    $dir = str_replace('\\', '/', dirname($script));
    if (str_ends_with($dir, '/admin')) {
        $dir = dirname($dir);
    }
    $base = ($dir === '/' || $dir === '.' || $dir === '\\') ? '' : rtrim($dir, '/');
    return $base;
}

/**
 * Build an absolute URL path under the app base.
 * Query-only paths like "?slug=guides" become "/?slug=guides" (or "/app/?slug=guides").
 */
function url(string $path = ''): string
{
    $base = base_path();
    $prefix = $base === '' ? '' : $base;

    if ($path === '' || $path === '/') {
        return $prefix === '' ? '/' : $prefix . '/';
    }

    // "?slug=x" or "?foo=1&bar=2"
    if (str_starts_with($path, '?')) {
        return ($prefix === '' ? '' : $prefix) . '/' . $path;
    }

    // Already app-absolute "/assets/..." — still prefix when app is in a subfolder
    if (str_starts_with($path, '/')) {
        // Avoid double-prefix if caller already included base
        if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
            return $path;
        }
        return $prefix . $path;
    }

    return $prefix . '/' . $path;
}

/**
 * Public URL for viewing a wiki page by slug.
 */
function page_url(string $slug): string
{
    $slug = DatabaseManager::sanitizeSlug($slug);
    if ($slug === '' || $slug === 'home') {
        return url();
    }
    return url('?slug=' . rawurlencode($slug));
}

/**
 * Escape for HTML text nodes / attributes.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Turn a title into a URL-safe slug.
 */
function slugify(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'page-' . time();
}
