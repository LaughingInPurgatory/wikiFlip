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

    <script>
    (function () {
        var toc = document.querySelector('.page-toc');
        var article = document.querySelector('.wiki-article');
        var links = toc ? toc.querySelector('.page-toc-links') : null;
        if (!toc || !article || !links) return;

        var headings = article.querySelectorAll('.wiki-article-content h2, .wiki-article-content h3, .subpage-list h2');
        var usedIds = {};
        headings.forEach(function (heading) {
            var base = (heading.textContent || 'section').trim().toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'section';
            var id = base;
            var suffix = 2;
            while (usedIds[id] || document.getElementById(id)) {
                id = base + '-' + suffix++;
            }
            usedIds[id] = true;
            heading.id = id;

            var link = document.createElement('a');
            link.href = '#' + id;
            link.textContent = heading.textContent;
            if (heading.tagName.toLowerCase() === 'h3') link.className = 'is-nested';
            links.appendChild(link);
        });

        if (headings.length) toc.hidden = false;
    })();
    </script>

    <?php if (!empty($loadEditor)): ?>
    <script>
        window.WIKIFLIP = {
            basePath: <?= json_encode(WikiApp\Core\base_path()) ?>,
            saveUrl: <?= json_encode(url('admin/save.php')) ?>,
            uploadUrl: <?= json_encode(url('admin/upload.php')) ?>,
            mediaBase: <?= json_encode(url('media.php')) ?>,
            csrfToken: <?= json_encode(\WikiApp\Core\Auth::csrfToken()) ?>,
            pageSlug: <?= json_encode((string) ($initialData['slug'] ?? ($_GET['slug'] ?? ''))) ?>,
            initialMarkdown: <?= json_encode((string) ($initialData['content'] ?? ''), JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="<?= e(url('assets/vendor/toastui/toastui-editor-all.min.js')) ?>"></script>
    <script src="<?= e(url('assets/js/editor.js')) ?>?v=<?= (int) @filemtime(WIKIFLIP_ROOT . '/assets/js/editor.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
