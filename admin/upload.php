<?php
/**
 * Upload image/PDF into a page folder (relative path for relative MD).
 * POST file + slug  →  { location: "filename.ext", url: "/media.php?..." }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/core/bootstrap.php';

use WikiApp\Core\Auth;
use WikiApp\Core\DatabaseManager;
use function WikiApp\Core\url;

Auth::requireLogin(json: true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

Auth::requireCsrf(json: true);

$slug = DatabaseManager::sanitizeSlug((string) ($_POST['slug'] ?? ''));
if ($slug === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Page slug is required before uploading media. Save the page slug first.']);
    exit;
}

// Ensure page exists (or create empty shell so media has a home)
if (DatabaseManager::getPageBySlug($slug) === null) {
    $parent = DatabaseManager::sanitizeSlug((string) ($_POST['parent'] ?? ''));
    $title = trim(strip_tags((string) ($_POST['title'] ?? $slug)));
    $ok = DatabaseManager::savePage([
        'title' => $title !== '' ? $title : $slug,
        'slug' => $slug,
        'parent' => $parent,
        'content' => '',
    ]);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not create page folder for upload.']);
        exit;
    }
}

$pageDir = DatabaseManager::getPageDirectory($slug);
if ($pageDir === null || !is_dir($pageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Page directory missing.']);
    exit;
}
if (!is_writable($pageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Page directory is not writable by the web server. Check volume permissions on pages/.']);
    exit;
}

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['file'];
$err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds PHP upload_max_filesize (raise limit in Docker php.ini; app allows up to 30 MB).',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Try again.',
        UPLOAD_ERR_NO_FILE => 'No file uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk (check permissions).',
        UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
    ];
    http_response_code(400);
    echo json_encode([
        'error' => $messages[$err] ?? ('Upload failed (error code ' . $err . ').'),
        'code' => $err,
    ]);
    exit;
}

$maxBytes = 30 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'File must be 30 MB or smaller.']);
    exit;
}

$tmp = (string) ($file['tmp_name'] ?? '');
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp) ?: '';

$allowedImages = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

$kind = null;
$ext = null;

if (isset($allowedImages[$mime])) {
    if (@getimagesize($tmp) === false) {
        http_response_code(415);
        echo json_encode(['error' => 'File is not a valid image.']);
        exit;
    }
    $kind = 'image';
    $ext = $allowedImages[$mime];
} elseif ($mime === 'application/pdf' || $mime === 'application/x-pdf') {
    $header = (string) file_get_contents($tmp, false, null, 0, 5);
    if (!str_starts_with($header, '%PDF-')) {
        http_response_code(415);
        echo json_encode(['error' => 'File is not a valid PDF.']);
        exit;
    }
    $kind = 'pdf';
    $ext = 'pdf';
} else {
    http_response_code(415);
    echo json_encode(['error' => 'Only JPEG, PNG, GIF, WebP, and PDF files are allowed.']);
    exit;
}

// Prefer a readable original basename, sanitized
$original = basename((string) ($file['name'] ?? 'file'));
$original = preg_replace('/[^A-Za-z0-9._-]+/', '-', $original) ?? 'file';
$original = trim($original, '.-');
if ($original === '' || !str_contains($original, '.')) {
    $uniqueName = bin2hex(random_bytes(4)) . '-' . time() . '.' . $ext;
} else {
    $base = pathinfo($original, PATHINFO_FILENAME);
    $base = substr($base !== '' ? $base : 'file', 0, 40);
    $uniqueName = $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
}

$destination = $pageDir . '/' . $uniqueName;
if (!move_uploaded_file($tmp, $destination)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store uploaded file.']);
    exit;
}
@chmod($destination, 0644);

// relative relative path for markdown; url for editor preview
$previewUrl = url('media.php?slug=' . rawurlencode($slug) . '&file=' . rawurlencode($uniqueName));

echo json_encode([
    'location' => $uniqueName,
    'url' => $previewUrl,
    'type' => $kind,
    'filename' => $uniqueName,
]);
