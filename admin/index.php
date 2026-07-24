<?php
/**
 * Admin dashboard — tabbed Pages list + Branding (title, logo, custom CSS).
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

/** Active tab: pages | branding */
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'pages');
if ($tab !== 'branding') {
    $tab = 'pages';
}

/**
 * Redirect back to admin with a tab (and optional flash via query is avoided — flash stays in-request only for branding).
 */
function admin_redirect(string $tab): never
{
    $q = $tab === 'branding' ? '?tab=branding' : '';
    header('Location: ' . url('admin/' . $q));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="tab" value="pages">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <input type="hidden" name="direction" value="up">
                <button type="submit" class="btn-icon" title="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
            </form>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="tab" value="pages">
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
                    <input type="hidden" name="tab" value="pages">
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
    </nav>

    <?php if ($flash !== ''): ?>
        <div class="save-status <?= $flashOk ? 'is-success' : 'is-error' ?>" role="status"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($tab === 'branding'): ?>
        <div class="admin-tab-panel" role="tabpanel" aria-labelledby="tab-branding" id="panel-branding">
            <form method="post" enctype="multipart/form-data" class="branding-form">
                <input type="hidden" name="action" value="save_branding">
                <input type="hidden" name="tab" value="branding">

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
                    <tbody id="adminPageTree">
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
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="action" value="reorder">
                                        <input type="hidden" name="tab" value="pages">
                                        <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="btn-icon" title="Move up" <?= $canUp ? '' : 'disabled' ?>>↑</button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="action" value="reorder">
                                        <input type="hidden" name="tab" value="pages">
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
                                            <input type="hidden" name="tab" value="pages">
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

  function rowBySlug(slug) {
    return tree.querySelector('tr.admin-tree-row[data-slug="' + CSS.escape(slug) + '"]');
  }

  function siblingRows(parentSlug) {
    // Top-level rows use data-parent=""
    return Array.prototype.slice.call(
      tree.querySelectorAll('tr.admin-tree-row[data-parent="' + CSS.escape(parentSlug) + '"]')
    );
  }

  function descendantsOf(parentSlug) {
    return siblingRows(parentSlug);
  }

  /** Row + all nested descendant rows, in document (tree) order. */
  function collectSubtree(slug) {
    var row = rowBySlug(slug);
    if (!row) return [];
    var out = [row];
    descendantsOf(slug).forEach(function (child) {
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
    descendantsOf(parentSlug).forEach(function (row) {
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

  function moveButtons(row) {
    return {
      up: row.querySelector('button.btn-icon[title="Move up"]'),
      down: row.querySelector('button.btn-icon[title="Move down"]')
    };
  }

  function refreshSiblingButtons(parentSlug) {
    var sibs = siblingRows(parentSlug);
    sibs.forEach(function (row, i) {
      var btns = moveButtons(row);
      if (btns.up) btns.up.disabled = i === 0;
      if (btns.down) btns.down.disabled = i === sibs.length - 1;
    });
  }

  /** Insert a contiguous block of rows before a reference row. */
  function insertBlockBefore(block, reference) {
    if (!reference || !block.length) return;
    var parent = reference.parentNode;
    block.forEach(function (r) {
      parent.insertBefore(r, reference);
    });
  }

  /** Insert a contiguous block of rows after the last node of another block. */
  function insertBlockAfter(block, afterNode) {
    if (!afterNode || !block.length) return;
    var parent = afterNode.parentNode;
    var ref = afterNode.nextSibling;
    block.forEach(function (r) {
      parent.insertBefore(r, ref);
    });
  }

  /**
   * Move slug's subtree up/down among its siblings in the live table
   * (expand/collapse state is preserved because we don't reload).
   */
  function moveInDom(slug, direction) {
    var row = rowBySlug(slug);
    if (!row) return false;
    var parentSlug = row.getAttribute('data-parent') || '';
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
    if (direction === 'up') {
      if (idx <= 0) return false;
      var prevSlug = sibs[idx - 1].getAttribute('data-slug');
      var prevRow = rowBySlug(prevSlug);
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
  });

  // In-place AJAX reorder — no full page reset of expand/scroll.
  tree.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM' || !tree.contains(form)) return;
    var actionInput = form.querySelector('input[name="action"]');
    if (!actionInput || actionInput.value !== 'reorder') return;

    e.preventDefault();
    if (busy) return;

    var slugInput = form.querySelector('input[name="slug"]');
    var dirInput = form.querySelector('input[name="direction"]');
    var slug = slugInput ? slugInput.value : '';
    var direction = dirInput ? dirInput.value : '';
    if (!slug || (direction !== 'up' && direction !== 'down')) return;

    var submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn && submitBtn.disabled) return;

    busy = true;
    tree.classList.add('is-reordering');

    var body = new FormData(form);
    body.set('ajax', '1');

    fetch(form.action || window.location.href, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          // Server refused (already at edge, race, etc.) — leave DOM as-is
          return;
        }
        moveInDom(slug, direction);
      })
      .catch(function () {
        // Fallback: full reload so order still matches disk
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
