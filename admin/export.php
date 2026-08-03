<?php
/**
 * Download a ZIP of all wiki content (pages, media, order, branding).
 *
 * Binary-safe: disables zlib/mod_deflate compression so the file stays a real .zip
 * (compressed bodies are often saved/auto-expanded as folders by browsers/OS).
 */

declare(strict_types=1);

// Must run before any output or bootstrap side-effects that buffer
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
// Always force .zip extension
if (!str_ends_with(strtolower($filename), '.zip')) {
    $filename .= '.zip';
}

$size = filesize($zipPath);
if ($size === false || $size < 22) {
    @unlink($zipPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export produced an empty or invalid ZIP.';
    exit;
}

// Verify local file is a real ZIP (PK signature)
$fh = fopen($zipPath, 'rb');
if ($fh === false) {
    @unlink($zipPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not read export file.';
    exit;
}
$magic = fread($fh, 4);
if ($magic === false || !str_starts_with($magic, "PK")) {
    fclose($fh);
    @unlink($zipPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export file is not a valid ZIP archive.';
    exit;
}
rewind($fh);

// Drop any buffers so the raw ZIP bytes are not re-compressed/mangled
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', '0');

// Force a saved .zip file (not an auto-expanded folder):
// - application/octet-stream avoids Safari/macOS “Open safe files” unpacking
// - filename / filename* always end in .zip
// - no Content-Encoding so zlib/mod_deflate cannot rewrite the body
$safeAscii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'wikiflip-backup.zip';
if (!str_ends_with(strtolower($safeAscii), '.zip')) {
    $safeAscii .= '.zip';
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

// Stream binary
fpassthru($fh);
fclose($fh);
@unlink($zipPath);
exit;
