<?php
/**
 * Admin page editor — Toast UI WYSIWYG that saves Markdown (content.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';

use WikiApp\Core\Auth;
use WikiApp\Core\DatabaseManager;
use function WikiApp\Core\e;
use function WikiApp\Core\url;

Auth::requireLogin();

$isAdmin = true;
$slug = DatabaseManager::sanitizeSlug((string) ($_GET['slug'] ?? ''));
$prefillParent = DatabaseManager::sanitizeSlug((string) ($_GET['parent'] ?? ''));
$isNew = ($slug === '');

if (!$isNew) {
    $pageData = DatabaseManager::getPageBySlug($slug);
    if ($pageData === null) {
        $initialData = [
            'title' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'parent' => $prefillParent,
            'content' => "Start writing…\n",
        ];
        $isNew = true;
    } else {
        $initialData = [
            'title' => (string) ($pageData['title'] ?? $slug),
            'slug' => (string) ($pageData['slug'] ?? $slug),
            'parent' => (string) ($pageData['parent'] ?? ''),
            'content' => (string) ($pageData['content'] ?? ''),
        ];
    }
} else {
    $initialData = [
        'title' => '',
        'slug' => '',
        'parent' => $prefillParent,
        'content' => "Start writing your content here…\n",
    ];
}

$parentOptions = DatabaseManager::getParentOptions($isNew ? null : $initialData['slug']);
if ($initialData['parent'] !== '') {
    $p = DatabaseManager::getPageBySlug($initialData['parent']);
    if ($p === null) {
        $initialData['parent'] = '';
    }
}

$pageTitle = $isNew ? 'New page' : ('Edit: ' . $initialData['title']);
$loadEditor = true;

require __DIR__ . '/../src/includes/header.php';
?>
<section class="admin-editor card">
    <div class="panel-header">
        <h2><?= $isNew ? 'Create page' : 'Edit page' ?></h2>
        <a class="btn btn-ghost" href="<?= e(url('admin/')) ?>">← All pages</a>
    </div>

    <form id="editForm" action="<?= e(url('admin/save.php')) ?>" method="POST">
        <input type="hidden" name="action" value="save_page">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="is_new" value="<?= $isNew ? '1' : '0' ?>">
        <?php if (!$isNew): ?>
            <input type="hidden" name="original_slug" value="<?= e($initialData['slug']) ?>">
        <?php endif; ?>
        <textarea id="contentMarkdown" name="content" hidden><?= e($initialData['content']) ?></textarea>

        <div class="form-group">
            <label for="pageTitle">Page title</label>
            <input type="text" id="pageTitle" name="title" required
                   value="<?= e($initialData['title']) ?>"
                   placeholder="Display name"
                   autocomplete="off">
        </div>

        <div class="form-group">
            <label for="pageSlug">URL slug</label>
            <input type="text" id="pageSlug" name="slug" required
                   value="<?= e($initialData['slug']) ?>"
                   placeholder="url-friendly-name"
                   pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                   title="Lowercase letters, numbers, and hyphens only"
                   <?= $isNew ? '' : 'readonly' ?>>
            <small class="hint">
                <?= $isNew
                    ? 'Auto-filled from the title; needed before uploading images/PDFs.'
                    : 'Slug is fixed after creation (folder name).' ?>
            </small>
        </div>

        <div class="form-group">
            <label for="pageParent">Parent page</label>
            <?php if ($initialData['slug'] === 'home'): ?>
                <input type="hidden" name="parent" value="">
                <p class="hint">Home is always a top-level page.</p>
            <?php else: ?>
                <select id="pageParent" name="parent">
                    <option value=""<?= $initialData['parent'] === '' ? ' selected' : '' ?>>
                        — None (top-level) —
                    </option>
                    <?php foreach ($parentOptions as $opt): ?>
                        <option value="<?= e($opt['slug']) ?>"
                            <?= $initialData['parent'] === $opt['slug'] ? ' selected' : '' ?>>
                            <?= e((string) ($opt['label'] ?? $opt['title'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="hint">
                    Nested pages → nested folders. Media is stored next to
                    <code>content.md</code>.
                </small>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Content (Markdown)</label>
            <div id="mdEditor" class="md-editor-host"></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="saveBtn">Save changes</button>
            <?php if (!$isNew): ?>
                <a class="btn btn-ghost" href="<?= e(url('?slug=' . rawurlencode($initialData['slug']))) ?>">View page</a>
                <a class="btn btn-ghost" href="<?= e(url('admin/edit.php?parent=' . rawurlencode($initialData['slug']))) ?>">+ Sub-page</a>
            <?php endif; ?>
        </div>
    </form>
</section>
<?php
require __DIR__ . '/../src/includes/footer.php';
