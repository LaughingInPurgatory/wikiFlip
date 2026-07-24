<?php
/**
 * Admin dashboard — collapsible tree, reorder siblings, site branding.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';

use WikiApp\Core\Auth;
use WikiApp\Core\DatabaseManager;
use WikiApp\Core\SiteSettings;
use function WikiApp\Core\e;
use function WikiApp\Core\url;

Auth::requireLogin();

$isAdmin = true;
$pageTitle = 'Admin';
$flash = '';
$flashOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete_page') {
        $toDelete = DatabaseManager::sanitizeSlug((string) ($_POST['slug'] ?? ''));
        if ($toDelete !== '' && $toDelete !== 'home') {
            DatabaseManager::deletePage($toDelete);
        }
        header('Location: ' . url('admin/'));
        exit;
    }

    if ($action === 'reorder') {
        $slug = DatabaseManager::sanitizeSlug((string) ($_POST['slug'] ?? ''));
        $dir = (string) ($_POST['direction'] ?? '');
        if ($slug !== '' && ($dir === 'up' || $dir === 'down')) {
            DatabaseManager::reorderSibling($slug, $dir);
        }
        header('Location: ' . url('admin/'));
        exit;
    }

    if ($action === 'save_branding') {
        $title = trim((string) ($_POST['site_title'] ?? ''));
        SiteSettings::saveTitle($title);

        if (!empty($_POST['clear_logo'])) {
            SiteSettings::clearLogo();
            $flash = 'Site title saved; logo reset to default.';
        } elseif (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file((string) $_FILES['logo']['tmp_name'])) {
            $tmp = (string) $_FILES['logo']['tmp_name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp) ?: '';
            $ok = SiteSettings::saveLogoFromUpload(
                $tmp,
                (string) ($_FILES['logo']['name'] ?? 'logo'),
                $mime
            );
            $flash = $ok ? 'Site branding updated.' : 'Title saved, but logo upload failed (use PNG, JPEG, GIF, WebP, or SVG).';
            $flashOk = $ok;
        } else {
            $flash = 'Site title saved.';
        }
    }
}

$tree = DatabaseManager::getPageTree();
$site = SiteSettings::get();

/**
 * @param array{title: string, slug: string, parent: string, updated_at?: string, children?: list<array>} $page
 * @param list<array> $siblings
 */
function render_admin_node(array $page, array $siblings, int $depth = 0): void
{
    $slug = (string) ($page['slug'] ?? '');
    $title = (string) ($page['title'] ?? $slug);
    $updated = !empty($page['updated_at'])
        ? date('Y-m-d H:i', strtotime((string) $page['updated_at']))
        : '—';
    $children = $page['children'] ?? [];
    $hasChildren = $children !== [];
    $siblingSlugs = array_map(static fn(array $s): string => (string) $s['slug'], $siblings);
    $idx = array_search($slug, $siblingSlugs, true);
    $canUp = is_int($idx) && $idx > 0;
    $canDown = is_int($idx) && $idx < count($siblingSlugs) - 1;
    $pad = $depth * 1.1;
    $rowId = 'node-' . preg_replace('/[^a-z0-9\-]/i', '-', $slug);
    ?>
    <tr class="admin-tree-row <?= $depth === 0 ? 'is-top' : 'is-nested' ?><?= $hasChildren ? ' has-children' : '' ?>"
        data-depth="<?= (int) $depth ?>"
        data-slug="<?= e($slug) ?>"
        id="<?= e($rowId) ?>">
        <td class="admin-tree-title" style="padding-left: <?= 0.65 + $pad ?>rem">
            <?php if ($hasChildren): ?>
                <button type="button" class="tree-toggle" aria-expanded="false"
                        aria-controls="children-of-<?= e($slug) ?>"
                        data-toggle-children="<?= e($slug) ?>"
                        title="Expand / collapse">▸</button>
            <?php else: ?>
                <span class="tree-toggle-spacer" aria-hidden="true"></span>
            <?php endif; ?>
            <strong><?= e($title) ?></strong>
            <?php if ($hasChildren): ?>
                <span class="badge-category"><?= count($children) ?> sub</span>
            <?php elseif ($depth === 0): ?>
                <span class="badge-category muted">top-level</span>
            <?php endif; ?>
        </td>
        <td><code><?= e($slug) ?></code></td>
        <td><?= e($updated) ?></td>
        <td class="actions admin-tree-actions">
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <input type="hidden" name="direction" value="up">
                <button type="submit" class="btn-icon" title="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
            </form>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <input type="hidden" name="direction" value="down">
                <button type="submit" class="btn-icon" title="Move down" <?= $canDown ? '' : 'disabled' ?>>↓</button>
            </form>
            <a href="<?= e(url('?slug=' . rawurlencode($slug))) ?>">View</a>
            <a href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Edit</a>
            <a href="<?= e(url('admin/edit.php?parent=' . rawurlencode($slug))) ?>">+ Sub</a>
            <?php if ($slug !== 'home'): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Delete this page?<?= $hasChildren ? ' Sub-pages move up one level.' : '' ?>');">
                    <input type="hidden" name="action" value="delete_page">
                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                    <button type="submit" class="link-danger">Delete</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    if ($hasChildren) {
        foreach ($children as $child) {
            // Nested rows start collapsed via CSS / JS using data-parent
            echo '<tbody class="admin-tree-children is-collapsed" data-parent="' . e($slug) . '" id="children-of-' . e($slug) . '" hidden>';
            // Only wrap once — fix: open tbody once outside loop
            break;
        }
        // Re-render properly with single tbody
    }
}

/**
 * Render tree with collapsible child groups.
 *
 * @param list<array{title: string, slug: string, children?: list<array>}> $nodes
 */
function render_admin_tree(array $nodes, int $depth = 0, string $parentSlug = ''): void
{
    $count = count($nodes);
    foreach ($nodes as $i => $page) {
        $slug = (string) ($page['slug'] ?? '');
        $title = (string) ($page['title'] ?? $slug);
        $updated = !empty($page['updated_at'])
            ? date('Y-m-d H:i', strtotime((string) $page['updated_at']))
            : '—';
        $children = $page['children'] ?? [];
        $hasChildren = $children !== [];
        $canUp = $i > 0;
        $canDown = $i < $count - 1;
        $pad = $depth * 1.1;
        ?>
        <tr class="admin-tree-row <?= $depth === 0 ? 'is-top' : 'is-nested' ?><?= $hasChildren ? ' has-children' : '' ?>"
            data-depth="<?= (int) $depth ?>"
            data-slug="<?= e($slug) ?>"
            data-parent="<?= e($parentSlug) ?>">
            <td class="admin-tree-title" style="padding-left: <?= 0.65 + $pad ?>rem">
                <?php if ($hasChildren): ?>
                    <button type="button" class="tree-toggle" aria-expanded="false"
                            data-toggle-children="<?= e($slug) ?>"
                            title="Expand / collapse">▸</button>
                <?php else: ?>
                    <span class="tree-toggle-spacer" aria-hidden="true"></span>
                <?php endif; ?>
                <strong><?= e($title) ?></strong>
                <?php if ($hasChildren): ?>
                    <span class="badge-category"><?= count($children) ?> sub</span>
                <?php elseif ($depth === 0): ?>
                    <span class="badge-category muted">top-level</span>
                <?php endif; ?>
            </td>
            <td><code><?= e($slug) ?></code></td>
            <td><?= e($updated) ?></td>
            <td class="actions admin-tree-actions">
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="btn-icon" title="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
                </form>
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="btn-icon" title="Move down" <?= $canDown ? '' : 'disabled' ?>>↓</button>
                </form>
                <a href="<?= e(url('?slug=' . rawurlencode($slug))) ?>">View</a>
                <a href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Edit</a>
                <a href="<?= e(url('admin/edit.php?parent=' . rawurlencode($slug))) ?>">+ Sub</a>
                <?php if ($slug !== 'home'): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this page?<?= $hasChildren ? ' Sub-pages move up one level.' : '' ?>');">
                        <input type="hidden" name="action" value="delete_page">
                        <input type="hidden" name="slug" value="<?= e($slug) ?>">
                        <button type="submit" class="link-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        if ($hasChildren) {
            // Child rows share data-parent for collapse; start hidden
            echo '<!-- children of ' . e($slug) . ' -->';
            // Mark children as collapsed by wrapping with a class on each child row
            // We'll set data-ancestor chain via depth + JS walk
            foreach ($children as $j => $child) {
                // Render with a flag - use recursive with collapsed class on nested
                render_admin_tree_child($child, $children, $depth + 1, $slug, true);
            }
        }
    }
}

/**
 * @param array{title: string, slug: string, children?: list<array>} $page
 * @param list<array> $siblings
 */
function render_admin_tree_child(array $page, array $siblings, int $depth, string $parentSlug, bool $collapsed): void
{
    $slug = (string) ($page['slug'] ?? '');
    $title = (string) ($page['title'] ?? $slug);
    $updated = !empty($page['updated_at'])
        ? date('Y-m-d H:i', strtotime((string) $page['updated_at']))
        : '—';
    $children = $page['children'] ?? [];
    $hasChildren = $children !== [];
    $siblingSlugs = array_map(static fn(array $s): string => (string) $s['slug'], $siblings);
    $idx = array_search($slug, $siblingSlugs, true);
    $canUp = is_int($idx) && $idx > 0;
    $canDown = is_int($idx) && $idx < count($siblingSlugs) - 1;
    $pad = $depth * 1.1;
    $hiddenAttr = $collapsed ? ' hidden' : '';
    $collapseClass = $collapsed ? ' is-collapsed-row' : '';
    ?>
    <tr class="admin-tree-row is-nested<?= $hasChildren ? ' has-children' : '' ?><?= $collapseClass ?>"
        data-depth="<?= (int) $depth ?>"
        data-slug="<?= e($slug) ?>"
        data-parent="<?= e($parentSlug) ?>"
        <?= $hiddenAttr ?>>
        <td class="admin-tree-title" style="padding-left: <?= 0.65 + $pad ?>rem">
            <?php if ($hasChildren): ?>
                <button type="button" class="tree-toggle" aria-expanded="false"
                        data-toggle-children="<?= e($slug) ?>"
                        title="Expand / collapse">▸</button>
            <?php else: ?>
                <span class="tree-toggle-spacer" aria-hidden="true"></span>
            <?php endif; ?>
            <strong><?= e($title) ?></strong>
            <?php if ($hasChildren): ?>
                <span class="badge-category"><?= count($children) ?> sub</span>
            <?php endif; ?>
        </td>
        <td><code><?= e($slug) ?></code></td>
        <td><?= e($updated) ?></td>
        <td class="actions admin-tree-actions">
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <input type="hidden" name="direction" value="up">
                <button type="submit" class="btn-icon" title="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
            </form>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <input type="hidden" name="direction" value="down">
                <button type="submit" class="btn-icon" title="Move down" <?= $canDown ? '' : 'disabled' ?>>↓</button>
            </form>
            <a href="<?= e(url('?slug=' . rawurlencode($slug))) ?>">View</a>
            <a href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Edit</a>
            <a href="<?= e(url('admin/edit.php?parent=' . rawurlencode($slug))) ?>">+ Sub</a>
            <?php if ($slug !== 'home'): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Delete this page?<?= $hasChildren ? ' Sub-pages move up one level.' : '' ?>');">
                    <input type="hidden" name="action" value="delete_page">
                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                    <button type="submit" class="link-danger">Delete</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    if ($hasChildren) {
        foreach ($children as $child) {
            // Nested under collapsed parent stay hidden until parent expanded
            render_admin_tree_child($child, $children, $depth + 1, $slug, true);
        }
    }
}

require __DIR__ . '/../src/includes/header.php';
?>
<section class="admin-panel card">
    <div class="panel-header">
        <h2>Site branding</h2>
    </div>
    <?php if ($flash !== ''): ?>
        <div class="save-status <?= $flashOk ? 'is-success' : 'is-error' ?>" role="status"><?= e($flash) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="branding-form">
        <input type="hidden" name="action" value="save_branding">
        <div class="branding-grid">
            <div class="form-group">
                <label for="site_title">Title under logo</label>
                <input type="text" id="site_title" name="site_title" required
                       value="<?= e($site['site_title']) ?>"
                       maxlength="80"
                       placeholder="WikiFlip">
                <small class="hint">Shown in the sidebar under the logo image.</small>
            </div>
            <div class="form-group">
                <label for="logo">Logo image</label>
                <div class="logo-preview-row">
                    <img class="logo-preview" src="<?= e(SiteSettings::logoUrl()) ?>?v=<?= time() ?>" alt="Current logo">
                    <div>
                        <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                        <small class="hint">PNG, JPEG, GIF, WebP, or SVG. Leave empty to keep current.</small>
                        <?php if (!empty($site['logo_file'])): ?>
                            <label class="checkbox-inline">
                                <input type="checkbox" name="clear_logo" value="1"> Reset to default logo
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save branding</button>
        </div>
    </form>
</section>

<section class="admin-panel card">
    <div class="panel-header">
        <h2>Pages &amp; categories</h2>
        <a class="btn btn-primary" href="<?= e(url('admin/edit.php')) ?>">+ New page</a>
    </div>

    <p class="hint admin-hint">
        Use <strong>↑ / ↓</strong> to reorder pages among siblings (sidebar follows this order).
        Categories with sub-pages start <strong>collapsed</strong> — click ▸ to expand.
    </p>

    <?php if ($tree === []): ?>
        <p class="empty-state">No pages yet. Create your first page to get started.</p>
    <?php else: ?>
        <table class="pages-table admin-tree-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="adminPageTree">
                <?php
                // Top-level only first; children rendered collapsed
                $topCount = count($tree);
                foreach ($tree as $i => $page) {
                    $slug = (string) ($page['slug'] ?? '');
                    $title = (string) ($page['title'] ?? $slug);
                    $updated = !empty($page['updated_at'])
                        ? date('Y-m-d H:i', strtotime((string) $page['updated_at']))
                        : '—';
                    $children = $page['children'] ?? [];
                    $hasChildren = $children !== [];
                    $canUp = $i > 0;
                    $canDown = $i < $topCount - 1;
                    ?>
                    <tr class="admin-tree-row is-top<?= $hasChildren ? ' has-children' : '' ?>"
                        data-depth="0"
                        data-slug="<?= e($slug) ?>"
                        data-parent="">
                        <td class="admin-tree-title">
                            <?php if ($hasChildren): ?>
                                <button type="button" class="tree-toggle" aria-expanded="false"
                                        data-toggle-children="<?= e($slug) ?>"
                                        title="Expand / collapse">▸</button>
                            <?php else: ?>
                                <span class="tree-toggle-spacer" aria-hidden="true"></span>
                            <?php endif; ?>
                            <strong><?= e($title) ?></strong>
                            <?php if ($hasChildren): ?>
                                <span class="badge-category"><?= count($children) ?> sub</span>
                            <?php else: ?>
                                <span class="badge-category muted">top-level</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($slug) ?></code></td>
                        <td><?= e($updated) ?></td>
                        <td class="actions admin-tree-actions">
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="reorder">
                                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn-icon" title="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
                            </form>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="reorder">
                                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn-icon" title="Move down" <?= $canDown ? '' : 'disabled' ?>>↓</button>
                            </form>
                            <a href="<?= e(url('?slug=' . rawurlencode($slug))) ?>">View</a>
                            <a href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Edit</a>
                            <a href="<?= e(url('admin/edit.php?parent=' . rawurlencode($slug))) ?>">+ Sub</a>
                            <?php if ($slug !== 'home'): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this page?<?= $hasChildren ? ' Sub-pages move up one level.' : '' ?>');">
                                    <input type="hidden" name="action" value="delete_page">
                                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                    <button type="submit" class="link-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                    if ($hasChildren) {
                        foreach ($children as $child) {
                            render_admin_tree_child($child, $children, 1, $slug, true);
                        }
                    }
                }
                ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<script>
(function () {
  var tree = document.getElementById('adminPageTree');
  if (!tree) return;

  function descendantsOf(parentSlug) {
    return Array.prototype.slice.call(
      tree.querySelectorAll('tr.admin-tree-row[data-parent="' + CSS.escape(parentSlug) + '"]')
    );
  }

  function setExpanded(parentSlug, expanded) {
    var btn = tree.querySelector('.tree-toggle[data-toggle-children="' + CSS.escape(parentSlug) + '"]');
    if (btn) {
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      btn.textContent = expanded ? '▾' : '▸';
    }
    descendantsOf(parentSlug).forEach(function (row) {
      if (expanded) {
        row.hidden = false;
        row.classList.remove('is-collapsed-row');
      } else {
        row.hidden = true;
        row.classList.add('is-collapsed-row');
        // collapse nested groups under this row
        var childSlug = row.getAttribute('data-slug');
        if (childSlug) setExpanded(childSlug, false);
      }
    });
  }

  tree.addEventListener('click', function (e) {
    var btn = e.target.closest('.tree-toggle');
    if (!btn || !tree.contains(btn)) return;
    e.preventDefault();
    var slug = btn.getAttribute('data-toggle-children');
    if (!slug) return;
    var open = btn.getAttribute('aria-expanded') === 'true';
    setExpanded(slug, !open);
  });
})();
</script>
<?php
require __DIR__ . '/../src/includes/footer.php';
