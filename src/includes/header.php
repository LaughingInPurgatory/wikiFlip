<?php
/**
 * Site chrome: deep-indigo glass sidenav + floating main.
 * Sidebar lists categories with nested sub-pages.
 */

declare(strict_types=1);

use WikiApp\Core\Auth;
use WikiApp\Core\DatabaseManager;
use WikiApp\Core\SiteSettings;
use function WikiApp\Core\e;
use function WikiApp\Core\url;

if (!defined('WIKIFLIP_ROOT')) {
    require_once __DIR__ . '/../core/bootstrap.php';
}

$siteBrand = SiteSettings::get();
$siteTitle = $siteBrand['site_title'];
$pageTitle = $pageTitle ?? $siteTitle;
$isAdmin = $isAdmin ?? false;
$currentSlug = $currentSlug ?? (string) ($_GET['slug'] ?? ($isAdmin ? '' : 'home'));
$loggedIn = Auth::check();
$adminUser = Auth::user();

$navTree = DatabaseManager::getPageTree();

/**
 * True if $currentSlug is this node or anywhere under it.
 *
 * @param array{slug?: string, children?: list<array>} $node
 */
if (!function_exists('nav_branch_contains')) {
    function nav_branch_contains(array $node, string $currentSlug): bool
    {
        if (($node['slug'] ?? '') === $currentSlug) {
            return true;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (nav_branch_contains($child, $currentSlug)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * @param list<array{title: string, slug: string, children?: list<array>}> $nodes
 * @param array<string, true> $renderedSlugs
 */
if (!function_exists('render_nav_tree')) {
    function render_nav_tree(array $nodes, string $currentSlug, bool $isAdmin, int $depth = 0, array &$renderedSlugs = []): void
    {
        foreach ($nodes as $navPage) {
            $navSlug = (string) ($navPage['slug'] ?? '');
            if ($navSlug === '' || isset($renderedSlugs[$navSlug])) {
                continue; // never output the same page twice
            }
            $renderedSlugs[$navSlug] = true;

            $navTitle = (string) ($navPage['title'] ?? $navSlug);
            $children = $navPage['children'] ?? [];
            // Drop any child that was already rendered (or is a self-duplicate)
            $children = array_values(array_filter(
                $children,
                static function (array $ch) use ($renderedSlugs, $navSlug): bool {
                    $s = (string) ($ch['slug'] ?? '');
                    return $s !== '' && $s !== $navSlug && !isset($renderedSlugs[$s]);
                }
            ));

            $isActive = (!$isAdmin && $navSlug === $currentSlug);
            $branchActive = !$isAdmin && !$isActive && nav_branch_contains($navPage, $currentSlug);
            $liClass = [];
            if ($depth === 0) {
                $liClass[] = 'nav-category';
            } else {
                $liClass[] = 'nav-subpage';
            }
            if ($isActive) {
                $liClass[] = 'is-current';
            }
            if ($branchActive) {
                $liClass[] = 'has-active-child';
            }
            ?>
            <li class="<?= e(implode(' ', $liClass)) ?>">
                <a<?= $isActive ? ' class="is-active"' : '' ?> href="<?= e(url('?slug=' . rawurlencode($navSlug))) ?>">
                    <?= e($navTitle) ?>
                </a>
                <?php if ($children !== []): ?>
                    <ul class="sidenav-subnav">
                        <?php render_nav_tree($children, $currentSlug, $isAdmin, $depth + 1, $renderedSlugs); ?>
                    </ul>
                <?php endif; ?>
            </li>
            <?php
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?><?= $pageTitle !== $siteTitle ? ' · ' . e($siteTitle) : '' ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int) @filemtime(WIKIFLIP_ROOT . '/assets/css/style.css') ?>">
    <?php if (SiteSettings::hasCustomCss()): ?>
    <link rel="stylesheet" href="<?= e(SiteSettings::customCssUrl()) ?>">
    <?php endif; ?>
    <?php if (!empty($loadEditor)): ?>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/toastui/toastui-editor.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/toastui/toastui-editor-dark.min.css')) ?>">
    <?php endif; ?>
</head>
<body class="<?= $isAdmin ? 'is-admin' : 'is-public' ?>">
    <div class="app-shell">
        <button type="button" class="menu-toggle" id="menuToggle" aria-label="Open navigation" aria-controls="siteSidenav" aria-expanded="false">☰</button>
        <div class="sidenav-backdrop" id="sidenavBackdrop" hidden></div>

        <aside class="sidenav" id="siteSidenav" aria-label="Site">
            <a class="brand" href="<?= e(url()) ?>">
                <img class="brand-logo" src="<?= e(SiteSettings::logoUrl()) ?>" alt="" width="120" height="120">
                <h1><?= e($siteTitle) ?></h1>
            </a>

            <ul class="sidenav-nav">
                <?php if ($navTree === []): ?>
                    <li><a href="<?= e(url('admin/edit.php')) ?>">Create a page…</a></li>
                <?php else: ?>
                    <?php render_nav_tree($navTree, $currentSlug, $isAdmin); ?>
                <?php endif; ?>
            </ul>

            <div class="sidenav-actions" role="toolbar" aria-label="Admin actions">
                <a class="sidenav-icon-btn<?= $isAdmin ? ' is-active' : '' ?>"
                   href="<?= e(url('admin/')) ?>"
                   title="Admin"
                   aria-label="Admin">
                    <span class="sidenav-icon-letter" aria-hidden="true">A</span>
                </a>
                <?php if ($loggedIn): ?>
                    <a class="sidenav-icon-btn"
                       href="<?= e(url('admin/edit.php')) ?>"
                       title="New page"
                       aria-label="New page">
                        <span class="sidenav-icon-plus" aria-hidden="true">+</span>
                    </a>
                    <a class="sidenav-icon-btn"
                       href="<?= e(url('admin/logout.php')) ?>"
                       title="Log out<?= $adminUser ? ' (' . $adminUser . ')' : '' ?>"
                       aria-label="Log out<?= $adminUser ? ' (' . e($adminUser) . ')' : '' ?>">
                        <svg class="sidenav-icon-svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M10 3a1 1 0 0 1 1 1v4a1 1 0 1 1-2 0V5H6a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h3v-3a1 1 0 1 1 2 0v4a1 1 0 0 1-1 1H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h4zm5.3 5.3a1 1 0 0 1 1.4 0l4 4a1 1 0 0 1 0 1.4l-4 4a1 1 0 1 1-1.4-1.4L17.58 14H10a1 1 0 1 1 0-2h7.58l-2.28-2.3a1 1 0 0 1 0-1.4z"/>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>

        </aside>

        <main class="wiki-container" id="main">
