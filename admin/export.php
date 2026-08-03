<?php
/**
 * Export wiki content as a real .zip file.
 *
 * Flow (avoids Safari treating POST binary responses as “openable” folders):
 *   1. POST + CSRF → build ZIP, store one-time token in session, 302 redirect
 *   2. GET ?download=TOKEN → stream application/octet-stream attachment .zip
 */

declare(strict_types=1);

@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('output_buffering', '0');

require_once __DIR__ . '/../src/core/bootstrap.php';

use WikiApp\Core\Auth;
use WikiApp\Core\ContentBackup;
use function WikiApp\Core\url;

Auth::requireLogin();

// ---- One-shot GET download (after prepare) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = (string) ($_GET['download'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing or invalid download token. Use Admin → Backup → Download backup .zip';
        exit;
    }

    $pending = ContentBackup::takePendingDownload($token);
    if ($pending === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Download expired or already used. Go back to Admin → Backup and export again.';
        exit;
    }

    ContentBackup::streamZipFile($pending['path'], $pending['filename']);
}

// ---- POST: prepare ZIP + redirect to GET download ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Use the Export button in Admin → Backup.';
    exit;
}

Auth::requireCsrf();

if (!ContentBackup::canExport()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ZIP export is not available (PHP ZipArchive required).';
    exit;
}

try {
    $token = ContentBackup::prepareDownload();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $e->getMessage();
    exit;
}

// 302 to a GET download — browsers save this as a proper .zip attachment
$target = url('admin/export.php?download=' . rawurlencode($token));
header('Location: ' . $target, true, 302);
header('Cache-Control: no-store');
exit;
