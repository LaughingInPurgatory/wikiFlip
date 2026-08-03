<?php
/**
 * Download a ZIP of all wiki content (pages, media, order, branding).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';

use WikiApp\Core\Auth;
use WikiApp\Core\ContentBackup;

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Use the Export button in Admin → Backup.';
    exit;
}

Auth::requireCsrf();

if (!ContentBackup::isAvailable()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ZIP support (PHP ZipArchive) is not enabled on this server.';
    exit;
}

try {
    $zipPath = ContentBackup::exportToTempFile();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $e->getMessage();
    exit;
}

$filename = ContentBackup::downloadFilename();
$size = filesize($zipPath);
if ($size === false) {
    @unlink($zipPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export file disappeared before download.';
    exit;
}

// Clear any prior output so the file is clean
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Length: ' . (string) $size);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$fp = fopen($zipPath, 'rb');
if ($fp === false) {
    @unlink($zipPath);
    http_response_code(500);
    echo 'Could not read export file.';
    exit;
}

fpassthru($fp);
fclose($fp);
@unlink($zipPath);
exit;
