<?php
/**
 * Admin dashboard — tabbed Pages list + Branding (title, logo, custom CSS).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';

use WikiApp\Core\Auth;
use WikiApp\Core\ContentBackup;
use WikiApp\Core\DatabaseManager;
use WikiApp\Core\SiteSettings;
use function WikiApp\Core\e;
use function WikiApp\Core\url;

// AJAX reorder/delete should get JSON 401 instead of an HTML login redirect
$wantsAjaxAuth = (string) ($_POST['ajax'] ?? '') === '1'
    || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
Auth::requireLogin($wantsAjaxAuth && $_SERVER['REQUEST_METHOD'] === 'POST');

$isAdmin = true;
$pageTitle = 'Admin';
$flash = '';
$flashOk = true;

/** Active tab: pages | branding | backup */
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'pages');
if (!in_array($tab, ['pages', 'branding', 'backup'], true)) {
    $tab = 'pages';
}

/**
 * Redirect back to admin with a tab (flash for branding/backup stays in-request only).
 */
function admin_redirect(string $tab): never
{
    $q = match ($tab) {
        'branding' => '?tab=branding',
        'backup' => '?tab=backup',
        default => '',
    };
    header('Location: ' . url('admin/' . $q));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf($wantsAjaxAuth);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete_page') {
        $toDelete = DatabaseManager::sanitizeSlug((string) ($_POST['slug'] ?? ''));
        if ($toDelete !== '' && $toDelete !== 'home') {
            DatabaseManager::deletePage($toDelete);
        }
        admin_redirect('pages');
    }

    if ($action === 'reorder') {
        $slug = DatabaseManager::sanitizeSlug((string) ($_POST['slug'] ?? ''));
        $dir = (string) ($_POST['direction'] ?? '');
        $ok = false;
        if ($slug !== '' && ($dir === 'up' || $dir === 'down')) {
            $ok = DatabaseManager::reorderSibling($slug, $dir);
        }

        // AJAX reorder keeps the tree open / scroll position; full POST still redirects.
        $wantsJson = (string) ($_POST['ajax'] ?? '') === '1'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => $ok,
                'slug' => $slug,
                'direction' => $dir,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        admin_redirect('pages');
    }

    if ($action === 'import_backup') {
        $tab = 'backup';
        $mode = (string) ($_POST['import_mode'] ?? 'replace');
        $mode = $mode === 'merge' ? 'merge' : 'replace';

        if (!ContentBackup::isAvailable()) {
            $flash = 'ZIP support is not available on this server (need PHP ZipArchive).';
            $flashOk = false;
        } elseif (empty($_FILES['backup_zip']['tmp_name']) || !is_uploaded_file((string) $_FILES['backup_zip']['tmp_name'])) {
            $err = (int) ($_FILES['backup_zip']['error'] ?? UPLOAD_ERR_NO_FILE);
            $flash = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload too large (max ~100 MB for full-site backups).',
                UPLOAD_ERR_NO_FILE => 'Choose a .zip backup file to import.',
                default => 'Upload failed (error code ' . $err . ').',
            };
            $flashOk = false;
        } else {
            $tmp = (string) $_FILES['backup_zip']['tmp_name'];
            $name = (string) ($_FILES['backup_zip']['name'] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                $flash = 'Please upload a .zip file exported from WikiFlip.';
                $flashOk = false;
            } else {
                $result = ContentBackup::importFromZipFile($tmp, $mode);
                $flash = $result['message'];
                $flashOk = (bool) $result['ok'];
            }
        }
    }

    if ($action === 'save_branding') {
        $tab = 'branding';
        $title = trim((string) ($_POST['site_title'] ?? ''));
        SiteSettings::saveTitle($title);

        $parts = ['Site title saved'];

        if (!empty($_POST['clear_logo'])) {
            SiteSettings::clearLogo();
            $parts[] = 'logo reset to default';
        } elseif (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file((string) $_FILES['logo']['tmp_name'])) {
            $tmp = (string) $_FILES['logo']['tmp_name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp) ?: '';
            $ok = SiteSettings::saveLogoFromUpload(
                $tmp,
                (string) ($_FILES['logo']['name'] ?? 'logo'),
                $mime
            );
            if ($ok) {
                $parts[] = 'logo updated';
            } else {
                $parts[] = 'logo upload failed (use PNG, JPEG, GIF, WebP, or SVG)';
                $flashOk = false;
            }
        }

        if (!empty($_POST['reset_css'])) {
            SiteSettings::clearCustomCss();
            $parts[] = 'custom CSS cleared (using default theme)';
        } elseif (array_key_exists('custom_css', $_POST)) {
            $css = (string) $_POST['custom_css'];
            $cssOk = SiteSettings::saveCustomCss($css);
            if (!$cssOk) {
                $parts[] = 'CSS save failed (max 500 KB)';
                $flashOk = false;
            } elseif (SiteSettings::hasCustomCss()) {
                $parts[] = 'custom CSS saved';
            } else {
                // Empty, or still identical to the bundled default → no override file
                $parts[] = 'using default theme CSS';
            }
        }

        $flash = implode('; ', $parts) . '.';
    }
}

$tree = DatabaseManager::getPageTree();
$site = SiteSettings::get();
$cssEditorValue = SiteSettings::getCssForEditor();
$hasCustomCss = SiteSettings::hasCustomCss();

/**
 * @param array{title: string, slug: string, children?: list<array>, updated_at?: string} $page
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
            <button type="button" class="btn-icon reorder-btn" data-direction="up"
                    title="Move up" aria-label="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
            <button type="button" class="btn-icon reorder-btn" data-direction="down"
                    title="Move down" aria-label="Move down" <?= $canDown ? '' : 'disabled' ?>>↓</button>
            <a href="<?= e(url('?slug=' . rawurlencode($slug))) ?>">View</a>
            <a href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Edit</a>
            <a href="<?= e(url('admin/edit.php?parent=' . rawurlencode($slug))) ?>">+ Sub</a>
            <?php if ($slug !== 'home'): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Delete this page?<?= $hasChildren ? ' Sub-pages move up one level.' : '' ?>');">
                    <input type="hidden" name="action" value="delete_page">
                    <input type="hidden" name="tab" value="pages">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                    <button type="submit" class="link-danger">Delete</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    if ($hasChildren) {
        foreach ($children as $child) {
            render_admin_tree_child($child, $children, $depth + 1, $slug, true);
        }
    }
}

require __DIR__ . '/../src/includes/header.php';
?>
<section class="admin-panel card">
    <div class="panel-header">
        <h2>Admin</h2>
        <?php if ($tab === 'pages'): ?>
            <a class="btn btn-primary" href="<?= e(url('admin/edit.php')) ?>">+ New page</a>
        <?php endif; ?>
    </div>

    <nav class="admin-tabs" role="tablist" aria-label="Admin sections">
        <a role="tab"
           class="admin-tab<?= $tab === 'pages' ? ' is-active' : '' ?>"
           href="<?= e(url('admin/?tab=pages')) ?>"
           aria-selected="<?= $tab === 'pages' ? 'true' : 'false' ?>"
           id="tab-pages">
            Pages &amp; categories
        </a>
        <a role="tab"
           class="admin-tab<?= $tab === 'branding' ? ' is-active' : '' ?>"
           href="<?= e(url('admin/?tab=branding')) ?>"
           aria-selected="<?= $tab === 'branding' ? 'true' : 'false' ?>"
           id="tab-branding">
            Branding &amp; CSS
        </a>
        <a role="tab"
           class="admin-tab<?= $tab === 'backup' ? ' is-active' : '' ?>"
           href="<?= e(url('admin/?tab=backup')) ?>"
           aria-selected="<?= $tab === 'backup' ? 'true' : 'false' ?>"
           id="tab-backup">
            Backup
        </a>
    </nav>

    <?php if ($flash !== ''): ?>
        <div class="save-status <?= $flashOk ? 'is-success' : 'is-error' ?>" role="status"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($tab === 'backup'): ?>
        <div class="admin-tab-panel" role="tabpanel" aria-labelledby="tab-backup" id="panel-backup">
            <?php if (!ContentBackup::isAvailable()): ?>
                <div class="save-status is-error" role="alert">
                    PHP ZipArchive is not available — export/import is disabled on this server.
                </div>
            <?php endif; ?>

            <h3 class="admin-section-title">Export</h3>
            <p class="hint admin-hint">
                Download a ZIP of the entire content volume: all pages, nested categories, media files,
                sidebar order, and branding (logo, title, custom CSS under <code>pages/.site</code>).
            </p>
            <form method="post" action="<?= e(url('admin/export.php')) ?>" class="backup-export-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" <?= ContentBackup::isAvailable() ? '' : 'disabled' ?>>
                        Download backup ZIP
                    </button>
                </div>
            </form>

            <h3 class="admin-section-title">Import</h3>
            <p class="hint admin-hint">
                Restore from a WikiFlip backup ZIP (or a ZIP whose root is a <code>pages/</code> tree).
                <strong>Replace</strong> wipes current content first; <strong>Merge</strong> overwrites matching paths and keeps the rest.
                Max size ~100&nbsp;MB.
            </p>
            <form method="post" enctype="multipart/form-data" class="backup-import-form">
                <input type="hidden" name="action" value="import_backup">
                <input type="hidden" name="tab" value="backup">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

                <div class="form-group">
                    <label for="backup_zip">Backup file (.zip)</label>
                    <input type="file" id="backup_zip" name="backup_zip" accept=".zip,application/zip"
                           <?= ContentBackup::isAvailable() ? 'required' : 'disabled' ?>>
                </div>

                <div class="form-group">
                    <label>Import mode</label>
                    <div class="radio-stack">
                        <label class="checkbox-inline">
                            <input type="radio" name="import_mode" value="replace" checked>
                            Replace all content (recommended for full restore)
                        </label>
                        <label class="checkbox-inline">
                            <input type="radio" name="import_mode" value="merge">
                            Merge into existing content
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"
                            <?= ContentBackup::isAvailable() ? '' : 'disabled' ?>
                            onclick="return confirm('Import will change live wiki content. Continue?');">
                        Import backup
                    </button>
                </div>
            </form>
        </div>
    <?php elseif ($tab === 'branding'): ?>
        <div class="admin-tab-panel" role="tabpanel" aria-labelledby="tab-branding" id="panel-branding">
            <form method="post" enctype="multipart/form-data" class="branding-form">
                <input type="hidden" name="action" value="save_branding">
                <input type="hidden" name="tab" value="branding">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

                <h3 class="admin-section-title">Site identity</h3>
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

                <h3 class="admin-section-title">Custom CSS</h3>
                <p class="hint admin-hint">
                    Paste or edit CSS to override the default theme. The box is prefilled with
                    <strong><?= $hasCustomCss ? 'your saved custom CSS' : 'the current default stylesheet' ?></strong>
                    as a reference. Saved CSS is loaded <em>after</em> the bundled theme, so later rules win.
                    You can keep the full file and tweak values, or delete everything and paste only the rules you need.
                    Reset (or save the original default unchanged) to drop the override.
                </p>
                <div class="form-group css-editor-group">
                    <div class="css-editor-meta">
                        <label for="custom_css">Stylesheet</label>
                        <span class="css-status <?= $hasCustomCss ? 'is-custom' : 'is-default' ?>">
                            <?= $hasCustomCss ? 'Using custom CSS' : 'Showing default (not yet saved as override)' ?>
                        </span>
                    </div>
                    <textarea id="custom_css" name="custom_css" class="css-editor" rows="22"
                              spellcheck="false"
                              autocomplete="off"
                              data-default-css-loaded="<?= $hasCustomCss ? '0' : '1' ?>"
                    ><?= e($cssEditorValue) ?></textarea>
                    <small class="hint">Max ~500 KB. Admin-only feature — CSS is served as-is to visitors when saved.</small>
                    <?php if ($hasCustomCss): ?>
                        <label class="checkbox-inline">
                            <input type="checkbox" name="reset_css" value="1"> Reset CSS to default (discard custom file)
                        </label>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save branding</button>
                    <a class="btn btn-ghost" href="<?= e(url('admin/?tab=branding')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="admin-tab-panel" role="tabpanel" aria-labelledby="tab-pages" id="panel-pages">
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
                    <tbody id="adminPageTree"
                           data-reorder-url="<?= e(url('admin/index.php')) ?>"
                           data-csrf-token="<?= e(Auth::csrfToken()) ?>">
                        <?php
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
                                    <button type="button" class="btn-icon reorder-btn" data-direction="up"
                                            title="Move up" aria-label="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
                                    <button type="button" class="btn-icon reorder-btn" data-direction="down"
                                            title="Move down" aria-label="Move down" <?= $canDown ? '' : 'disabled' ?>>↓</button>
                                    <a href="<?= e(url('?slug=' . rawurlencode($slug))) ?>">View</a>
                                    <a href="<?= e(url('admin/edit.php?slug=' . rawurlencode($slug))) ?>">Edit</a>
                                    <a href="<?= e(url('admin/edit.php?parent=' . rawurlencode($slug))) ?>">+ Sub</a>
                                    <?php if ($slug !== 'home'): ?>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this page?<?= $hasChildren ? ' Sub-pages move up one level.' : '' ?>');">
                                            <input type="hidden" name="action" value="delete_page">
                                            <input type="hidden" name="tab" value="pages">
                                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
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
        </div>
    <?php endif; ?>
</section>
<?php if ($tab === 'pages'): ?>
<script>
(function () {
  var tree = document.getElementById('adminPageTree');
  if (!tree) return;

  var busy = false;
  var reorderUrl = tree.getAttribute('data-reorder-url') || (window.location.pathname || '/admin/');
  var csrfToken = tree.getAttribute('data-csrf-token') || '';

  function rowBySlug(slug) {
    return tree.querySelector('tr.admin-tree-row[data-slug="' + CSS.escape(slug) + '"]');
  }

  /**
   * Direct children of a parent. Top-level uses data-depth="0" because empty
   * [data-parent=""] attribute selectors are unreliable in some browsers.
   */
  function siblingRows(parentSlug) {
    if (parentSlug === '' || parentSlug == null) {
      return Array.prototype.slice.call(
        tree.querySelectorAll('tr.admin-tree-row[data-depth="0"]')
      );
    }
    return Array.prototype.slice.call(
      tree.querySelectorAll('tr.admin-tree-row[data-parent="' + CSS.escape(parentSlug) + '"]')
    );
  }

  function parentOfRow(row) {
    var p = row.getAttribute('data-parent');
    return p == null ? '' : p;
  }

  /** Row + all nested descendant rows, in document (tree) order. */
  function collectSubtree(slug) {
    var row = rowBySlug(slug);
    if (!row) return [];
    var out = [row];
    siblingRows(slug).forEach(function (child) {
      var childSlug = child.getAttribute('data-slug');
      if (childSlug) {
        out = out.concat(collectSubtree(childSlug));
      }
    });
    return out;
  }

  function setExpanded(parentSlug, expanded) {
    var btn = tree.querySelector('.tree-toggle[data-toggle-children="' + CSS.escape(parentSlug) + '"]');
    if (btn) {
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      btn.textContent = expanded ? '▾' : '▸';
    }
    siblingRows(parentSlug).forEach(function (row) {
      if (expanded) {
        row.hidden = false;
        row.classList.remove('is-collapsed-row');
      } else {
        row.hidden = true;
        row.classList.add('is-collapsed-row');
        var childSlug = row.getAttribute('data-slug');
        if (childSlug) setExpanded(childSlug, false);
      }
    });
  }

  function refreshSiblingButtons(parentSlug) {
    var sibs = siblingRows(parentSlug);
    sibs.forEach(function (row, i) {
      var up = row.querySelector('.reorder-btn[data-direction="up"]');
      var down = row.querySelector('.reorder-btn[data-direction="down"]');
      if (up) up.disabled = i === 0;
      if (down) down.disabled = i === sibs.length - 1;
    });
  }

  /** Place a contiguous block of rows before reference (not in the block). */
  function insertBlockBefore(block, reference) {
    if (!reference || !block.length) return;
    var parent = reference.parentNode;
    var frag = document.createDocumentFragment();
    block.forEach(function (r) { frag.appendChild(r); });
    parent.insertBefore(frag, reference);
  }

  /** Place a contiguous block of rows after afterNode (not in the block). */
  function insertBlockAfter(block, afterNode) {
    if (!afterNode || !block.length) return;
    var parent = afterNode.parentNode;
    var ref = afterNode.nextSibling;
    // Skip past any nodes that are part of the moving block
    var inBlock = Object.create(null);
    block.forEach(function (r) { inBlock[r.getAttribute('data-slug') || ''] = true; });
    while (ref && ref.nodeType === 1 && inBlock[ref.getAttribute('data-slug') || '']) {
      ref = ref.nextSibling;
    }
    var frag = document.createDocumentFragment();
    block.forEach(function (r) { frag.appendChild(r); });
    parent.insertBefore(frag, ref);
  }

  /**
   * Move slug's subtree up/down among its siblings in the live table.
   * Expand/collapse state is preserved (no reload).
   */
  function moveInDom(slug, direction) {
    var row = rowBySlug(slug);
    if (!row) return false;
    var parentSlug = parentOfRow(row);
    var sibs = siblingRows(parentSlug);
    var idx = -1;
    for (var i = 0; i < sibs.length; i++) {
      if (sibs[i].getAttribute('data-slug') === slug) {
        idx = i;
        break;
      }
    }
    if (idx < 0) return false;

    var block = collectSubtree(slug);
    if (!block.length) return false;

    if (direction === 'up') {
      if (idx <= 0) return false;
      var prevRow = sibs[idx - 1];
      if (!prevRow) return false;
      insertBlockBefore(block, prevRow);
    } else if (direction === 'down') {
      if (idx >= sibs.length - 1) return false;
      var nextSlug = sibs[idx + 1].getAttribute('data-slug');
      var nextBlock = collectSubtree(nextSlug);
      if (!nextBlock.length) return false;
      insertBlockAfter(block, nextBlock[nextBlock.length - 1]);
    } else {
      return false;
    }

    refreshSiblingButtons(parentSlug);
    return true;
  }

  function saveReorder(slug, direction) {
    var body = new FormData();
    body.set('action', 'reorder');
    body.set('slug', slug);
    body.set('direction', direction);
    body.set('tab', 'pages');
    body.set('ajax', '1');
    body.set('csrf_token', csrfToken);

    return fetch(reorderUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function (res) {
      if (res.status === 401 || res.redirected) {
        throw new Error('auth');
      }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    });
  }

  tree.addEventListener('click', function (e) {
    var toggle = e.target.closest('.tree-toggle');
    if (toggle && tree.contains(toggle)) {
      e.preventDefault();
      var tSlug = toggle.getAttribute('data-toggle-children');
      if (!tSlug) return;
      var open = toggle.getAttribute('aria-expanded') === 'true';
      setExpanded(tSlug, !open);
      return;
    }

    var btn = e.target.closest('.reorder-btn');
    if (!btn || !tree.contains(btn) || btn.disabled) return;
    e.preventDefault();
    e.stopPropagation();
    if (busy) return;

    var row = btn.closest('tr.admin-tree-row');
    if (!row) return;
    var slug = row.getAttribute('data-slug');
    var direction = btn.getAttribute('data-direction');
    if (!slug || (direction !== 'up' && direction !== 'down')) return;

    // Optimistic UI: move first so expand state / scroll stay put and feedback is instant
    if (!moveInDom(slug, direction)) return;

    busy = true;
    tree.classList.add('is-reordering');

    saveReorder(slug, direction)
      .then(function (data) {
        if (!data || !data.ok) {
          // Server rejected — resync from disk
          window.location.reload();
        }
      })
      .catch(function () {
        window.location.reload();
      })
      .finally(function () {
        busy = false;
        tree.classList.remove('is-reordering');
      });
  });
})();
</script>
<?php endif; ?>
<?php
require __DIR__ . '/../src/includes/footer.php';
