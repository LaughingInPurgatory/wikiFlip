<?php
/**
 * Close main content, site footer, scripts.
 */

declare(strict_types=1);

use function WikiApp\Core\e;
use function WikiApp\Core\url;

$isAdmin = $isAdmin ?? false;
?>
        </main>
        <footer class="site-footer">
            <p>WikiFlip &copy; 2026 Laughing In Purgatory</p>
        </footer>
    </div>

    <script>
    (function () {
        var toggle = document.getElementById('menuToggle');
        var nav = document.getElementById('siteSidenav');
        var backdrop = document.getElementById('sidenavBackdrop');
        if (!toggle || !nav) return;

        function setOpen(open) {
            nav.classList.toggle('is-open', open);
            if (backdrop) {
                backdrop.classList.toggle('is-open', open);
                backdrop.hidden = !open;
            }
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        }

        toggle.addEventListener('click', function () {
            setOpen(!nav.classList.contains('is-open'));
        });
        if (backdrop) {
            backdrop.addEventListener('click', function () { setOpen(false); });
        }
        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 992px)').matches) {
                    setOpen(false);
                }
            });
        });
    })();
    </script>

    <?php if (!empty($loadEditor)): ?>
    <script>
        window.WIKIFLIP = {
            basePath: <?= json_encode(WikiApp\Core\base_path()) ?>,
            saveUrl: <?= json_encode(url('admin/save.php')) ?>,
            uploadUrl: <?= json_encode(url('admin/upload.php')) ?>,
            mediaBase: <?= json_encode(url('media.php')) ?>,
            pageSlug: <?= json_encode((string) ($initialData['slug'] ?? ($_GET['slug'] ?? ''))) ?>,
            initialMarkdown: <?= json_encode((string) ($initialData['content'] ?? ''), JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="<?= e(url('assets/vendor/toastui/toastui-editor-all.min.js')) ?>"></script>
    <script src="<?= e(url('assets/js/editor.js')) ?>?v=<?= (int) @filemtime(WIKIFLIP_ROOT . '/assets/js/editor.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
