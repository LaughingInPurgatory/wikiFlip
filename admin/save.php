<?php
/**
 * POST endpoint: save page as relative content.md
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/core/bootstrap.php';

use WikiApp\Core\Auth;
use WikiApp\Core\DatabaseManager;
use WikiApp\Core\Markdown;

Auth::requireLogin(json: true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

Auth::requireCsrf(json: true);

if ((string) ($_POST['action'] ?? '') !== 'save_page') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$title = trim(strip_tags((string) ($_POST['title'] ?? '')));
$slug = DatabaseManager::sanitizeSlug((string) ($_POST['slug'] ?? ''));
$body = (string) ($_POST['content'] ?? ''); // markdown body
$isNew = (($_POST['is_new'] ?? '0') === '1');
$originalSlug = DatabaseManager::sanitizeSlug((string) ($_POST['original_slug'] ?? ''));
$parent = DatabaseManager::sanitizeSlug((string) ($_POST['parent'] ?? ''));

if ($title === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Title is required.']);
    exit;
}

if ($slug === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid URL slug is required.']);
    exit;
}

if ($isNew && DatabaseManager::getPageBySlug($slug) !== null) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => "A page with slug “{$slug}” already exists."]);
    exit;
}

if (!$isNew) {
    if ($originalSlug === '' || $slug !== $originalSlug) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Page slugs cannot be changed after creation.']);
        exit;
    }
    if (DatabaseManager::getPageBySlug($originalSlug) === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'The page being edited no longer exists.']);
        exit;
    }
}

if ($slug === 'home') {
    $parent = '';
}

if ($parent !== '') {
    if ($parent === $slug) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A page cannot be its own parent.']);
        exit;
    }
    if (DatabaseManager::getPageBySlug($parent) === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Selected parent page does not exist.']);
        exit;
    }
    if (!$isNew && DatabaseManager::isDescendantOf($parent, $slug)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Cannot move a page under one of its own sub-pages.']);
        exit;
    }
}

// Light sanitization on embedded HTML in markdown
$body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body) ?? $body;
$body = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/iu', '', $body) ?? $body;
$body = Markdown::relativizeMediaPaths($body, $slug);

$success = DatabaseManager::savePage([
    'title' => $title,
    'slug' => $slug,
    'parent' => $parent,
    'content' => $body,
]);

if ($success) {
    echo json_encode([
        'success' => true,
        'message' => 'Page saved successfully.',
        'slug' => $slug,
        'parent' => $parent,
        'view_url' => WikiApp\Core\url('?slug=' . rawurlencode($slug)),
        'edit_url' => WikiApp\Core\url('admin/edit.php?slug=' . rawurlencode($slug)),
    ]);
    exit;
}

http_response_code(500);
echo json_encode(['success' => false, 'message' => 'Could not write content.md. Check permissions on pages/.']);
