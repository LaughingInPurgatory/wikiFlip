<?php
/**
 * Public front controller — renders a wiki page by slug.
 * Nested categories show breadcrumbs and direct sub-pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/core/bootstrap.php';

use WikiApp\Core\DatabaseManager;
use WikiApp\Core\Markdown;
use function WikiApp\Core\e;
use function WikiApp\Core\url;

$slug = DatabaseManager::sanitizeSlug((string) ($_GET['slug'] ?? 'home'));
if ($slug === '') {
    $slug = 'home';
}

$pageData = DatabaseManager::getPageBySlug($slug);
$currentSlug = $slug;
$isAdmin = false;

if ($pageData === null) {
    http_response_code(404);
    $pageTitle = 'Page not found';
    require __DIR__ . '/src/includes/header.php';
    ?>
    <article class="content-body wiki-article card">
        <h1>404 — Page not found</h1>
        <p>No page exists for slug <code><?= e($slug) ?></code>.</p>
        <p>
            <a href="<?= e(url()) ?>">Go home</a>
            ·
            <a href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Create this page</a>
        </p>
    </article>
    <?php
    require __DIR__ . '/src/includes/footer.php';
    exit;
}

$pageTitle = (string) ($pageData['title'] ?? $slug);
$markdownBody = (string) ($pageData['content'] ?? '');
$contentHtml = Markdown::renderPageBody($markdownBody, $slug);
$ancestors = DatabaseManager::getAncestors($slug);
$children = DatabaseManager::getChildPages($slug);

require __DIR__ . '/src/includes/header.php';
?>
<div class="wiki-reading-layout">
    <article class="content-body wiki-article card">
        <?php if ($ancestors !== []): ?>
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <?php foreach ($ancestors as $crumb): ?>
                    <a href="<?= e(url('?slug=' . rawurlencode((string) $crumb['slug']))) ?>"><?= e((string) $crumb['title']) ?></a>
                    <span class="breadcrumb-sep" aria-hidden="true">/</span>
                <?php endforeach; ?>
                <span class="breadcrumb-current"><?= e($pageTitle) ?></span>
            </nav>
        <?php endif; ?>

        <header class="article-header">
            <div class="article-kicker"><span aria-hidden="true"></span>WikiFlip guide</div>
            <h1><?= e($pageTitle) ?></h1>
            <?php if ($children !== []): ?>
                <p class="meta"><?= count($children) ?> sub-page<?= count($children) === 1 ? '' : 's' ?></p>
            <?php elseif (!empty($pageData['updated_at'])): ?>
                <p class="meta">Updated <?= e(date('M j, Y g:i A', strtotime((string) $pageData['updated_at']))) ?></p>
            <?php endif; ?>
        </header>

        <div class="wiki-article-content">
            <?= $contentHtml ?>
        </div>

        <?php if ($children !== []): ?>
            <section class="subpage-list" aria-label="Sub-pages">
                <h2>In this section</h2>
                <ul>
                    <?php foreach ($children as $child): ?>
                        <li>
                            <a href="<?= e(url('?slug=' . rawurlencode((string) $child['slug']))) ?>">
                                <span><?= e((string) $child['title']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <p class="article-actions">
            <a class="btn btn-primary" href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Edit page</a>
            <a class="btn btn-ghost" href="<?= e(url('admin/edit.php?parent=' . rawurlencode($slug))) ?>">+ Sub-page</a>
        </p>
    </article>

    <aside class="page-toc" aria-label="On this page" hidden>
        <div class="page-toc-label">On this page</div>
        <nav class="page-toc-links"></nav>
    </aside>
</div>
<?php
require __DIR__ . '/src/includes/footer.php';
