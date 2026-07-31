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
        var nav = document.querySelector('.sidenav-nav');
        if (!nav) return;

        var storageKey = 'wikiflip.nav-category-state';
        var state = {};
        try {
            state = JSON.parse(window.localStorage.getItem(storageKey) || '{}') || {};
        } catch (error) {
            state = {};
        }

        var hashMatch = /^#wikiflip-nav-(expanded|collapsed)-(.+)$/.exec(window.location.hash);
        var hashState = hashMatch ? {
            slug: decodeURIComponent(hashMatch[2]),
            expanded: hashMatch[1] === 'expanded'
        } : null;

        function setExpanded(branch, link, expanded) {
            branch.classList.toggle('is-expanded', expanded);
            branch.classList.toggle('is-collapsed', !expanded);
            link.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        function saveState(slug, expanded) {
            state[slug] = expanded ? 'expanded' : 'collapsed';
            try {
                window.localStorage.setItem(storageKey, JSON.stringify(state));
            } catch (error) {
                // Navigation still works when storage is unavailable.
            }
        }

        nav.querySelectorAll('li.has-children[data-nav-branch]').forEach(function (branch) {
            var slug = branch.getAttribute('data-nav-branch');
            var link = Array.prototype.find.call(branch.children, function (child) {
                return child.tagName === 'A';
            });
            if (!slug || !link) return;

            if (state[slug] === 'expanded' || state[slug] === 'collapsed') {
                setExpanded(branch, link, state[slug] === 'expanded');
            }
            if (hashState && hashState.slug === slug) {
                setExpanded(branch, link, hashState.expanded);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', window.location.href.split('#')[0]);
                }
            }

            link.addEventListener('click', function () {
                var expanded = branch.classList.contains('is-expanded');
                var nextExpanded = !expanded;
                setExpanded(branch, link, nextExpanded);
                saveState(slug, nextExpanded);

                var target = link.getAttribute('href') || '';
                target = target.split('#')[0];
                link.setAttribute('href', target + '#wikiflip-nav-'
                    + (nextExpanded ? 'expanded-' : 'collapsed-')
                    + encodeURIComponent(slug));
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

    <script>
    (function () {
        var content = document.querySelector('.wiki-article-content');
        if (!content) return;

        var overlay = document.createElement('div');
        overlay.className = 'image-lightbox';
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');

        var backdrop = document.createElement('button');
        backdrop.type = 'button';
        backdrop.className = 'image-lightbox-backdrop';
        backdrop.setAttribute('aria-label', 'Close image preview');

        var panel = document.createElement('div');
        panel.className = 'image-lightbox-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-label', 'Image preview');

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'image-lightbox-close';
        close.setAttribute('aria-label', 'Close image preview');
        close.textContent = '×';

        var image = document.createElement('img');
        image.className = 'image-lightbox-image';

        var caption = document.createElement('p');
        caption.className = 'image-lightbox-caption';

        panel.append(close, image, caption);
        overlay.append(backdrop, panel);
        document.body.appendChild(overlay);

        var activeTrigger = null;

        function closePreview() {
            overlay.hidden = true;
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('image-lightbox-open');
            image.removeAttribute('src');
            if (activeTrigger) activeTrigger.focus();
            activeTrigger = null;
        }

        function openPreview(trigger) {
            activeTrigger = trigger;
            image.src = trigger.currentSrc || trigger.src;
            image.alt = trigger.alt || 'Expanded image';
            caption.textContent = trigger.alt || '';
            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('image-lightbox-open');
            close.focus();
        }

        content.querySelectorAll('img').forEach(function (img) {
            img.tabIndex = 0;
            img.setAttribute('role', 'button');
            if (!img.getAttribute('aria-label')) {
                img.setAttribute('aria-label', img.alt ? 'Open image: ' + img.alt : 'Open image');
            }
        });

        content.addEventListener('click', function (event) {
            var img = event.target.closest('img');
            if (!img || !content.contains(img)) return;
            event.preventDefault();
            openPreview(img);
        });

        content.addEventListener('keydown', function (event) {
            if ((event.key !== 'Enter' && event.key !== ' ') || event.target.tagName !== 'IMG') return;
            event.preventDefault();
            openPreview(event.target);
        });

        backdrop.addEventListener('click', closePreview);
        close.addEventListener('click', closePreview);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !overlay.hidden) closePreview();
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
