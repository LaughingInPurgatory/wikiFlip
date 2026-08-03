<?php
/**
 * Download a .tar.gz of all wiki content (pages, media, order, branding).
 *
 * Binary-safe: disables zlib/mod_deflate so the tarball is not double-compressed.
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

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Use the Export button in Admin → Backup.';
    exit;
}

Auth::requireCsrf();

if (!ContentBackup::canExport()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Tarball export is not available (PHP PharData required).';
    exit;
}

try {
    $archivePath = ContentBackup::exportToTempFile();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $e->getMessage();
    exit;
}

$filename = ContentBackup::downloadFilename();
if (!str_ends_with(strtolower($filename), '.tar.gz')) {
    $filename = preg_replace('/\.(zip|tar)$/i', '', $filename) . '.tar.gz';
}

$size = filesize($archivePath);
if ($size === false || $size < 20) {
    @unlink($archivePath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export produced an empty or invalid tarball.';
    exit;
}

// gzip magic 1f 8b
$fh = fopen($archivePath, 'rb');
if ($fh === false) {
    @unlink($archivePath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not read export file.';
    exit;
}
$magic = fread($fh, 2);
if ($magic === false || $magic !== "\x1f\x8b") {
    fclose($fh);
    @unlink($archivePath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export file is not a valid gzip tarball.';
    exit;
}
rewind($fh);

while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', '0');

$safeAscii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'wikiflip-backup.tar.gz';
if (!str_ends_with(strtolower($safeAscii), '.tar.gz')) {
    $safeAscii = rtrim($safeAscii, '.') . '.tar.gz';
}
$disposition = 'attachment; filename="' . $safeAscii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);

header('Content-Type: application/octet-stream');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . (string) $size);
header('Content-Disposition: ' . $disposition);
header('Content-Description: File Transfer');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: public');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');

fpassthru($fh);
fclose($fh);
@unlink($archivePath);
exit;
