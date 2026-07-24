<?php

declare(strict_types=1);

namespace WikiApp\Core;

/**
 * Folder-per-page storage.
 *
 *   pages/{slug}/content.md
 *   pages/{parent}/{slug}/content.md
 *   pages/{parent}/{slug}/image.png   ← relative media next to content.md
 *   pages/.order.json                 ← sibling order under root
 *   pages/{slug}/.order.json          ← sibling order under a parent
 *
 * content.md starts with `# Title` then the markdown body.
 */
class DatabaseManager
{
    private const PAGES_DIR = WIKIFLIP_ROOT . '/pages/';
    public const CONTENT_FILE = 'content.md';
    /** @deprecated legacy */
    private const LEGACY_JSON = 'page.json';
    private const LAYOUT_STAMP = '.wikiflip-layout-v2';
    private const ORDER_FILE = '.order.json';

    private static bool $migrated = false;

    /** @var array<string, string>|null slug => absolute path to content.md (built once per request) */
    private static ?array $pathIndex = null;

    /** @var array<string, array|null> request-local page data cache */
    private static array $pageCache = [];

    protected function __construct() {}

    public static function getPagesDir(): string
    {
        return self::PAGES_DIR;
    }

    /** Drop path/page caches after filesystem changes (save/delete/move). */
    public static function invalidateCache(): void
    {
        self::$pathIndex = null;
        self::$pageCache = [];
    }

    public static function migrateLegacyLayout(): void
    {
        if (self::$migrated) {
            return;
        }
        self::$migrated = true;

        if (!is_dir(self::PAGES_DIR)) {
            return;
        }

        // Cheap: root-level legacy *.json
        foreach (glob(self::PAGES_DIR . '*.json') ?: [] as $file) {
            self::migrateLegacyJsonFile($file);
        }

        $stamp = self::PAGES_DIR . self::LAYOUT_STAMP;
        // Full tree walk only until migration is complete (once per volume)
        if (is_file($stamp)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PAGES_DIR, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $jsonFiles = [];
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $name = $fileInfo->getFilename();
            if ($name === self::CONTENT_FILE || $name === self::LAYOUT_STAMP) {
                continue;
            }
            if ($name === self::LEGACY_JSON || str_ends_with(strtolower($name), '.json')) {
                $jsonFiles[] = $fileInfo->getPathname();
            }
        }
        foreach ($jsonFiles as $file) {
            self::migrateLegacyJsonFile($file);
        }

        @file_put_contents($stamp, "ok\n");
    }

    /**
     * Build slug → content.md path map once per request.
     *
     * @return array<string, string>
     */
    private static function pathIndex(): array
    {
        if (self::$pathIndex !== null) {
            return self::$pathIndex;
        }

        self::migrateLegacyLayout();
        self::$pathIndex = [];

        if (!is_dir(self::PAGES_DIR)) {
            return self::$pathIndex;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                self::PAGES_DIR,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
            )
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile() || $fileInfo->getFilename() !== self::CONTENT_FILE) {
                continue;
            }
            $path = $fileInfo->getPathname();
            $slug = self::sanitizeSlug(basename(dirname($path)));
            if ($slug === '') {
                continue;
            }
            // Prefer shallower paths if a slug appears twice
            if (isset(self::$pathIndex[$slug])) {
                $existingDepth = substr_count(str_replace('\\', '/', self::$pathIndex[$slug]), '/');
                $newDepth = substr_count(str_replace('\\', '/', $path), '/');
                if ($newDepth >= $existingDepth) {
                    continue;
                }
            }
            self::$pathIndex[$slug] = $path;
        }

        return self::$pathIndex;
    }

    /**
     * Convert page.json / legacy slug.json into content.md in the page folder.
     */
    private static function migrateLegacyJsonFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }
        $base = basename($filePath);
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        if ($base === self::LEGACY_JSON) {
            $dir = dirname($filePath);
            $slug = self::sanitizeSlug(basename($dir));
        } else {
            $slug = self::sanitizeSlug(basename($filePath, '.json'));
            $parentHint = self::sanitizeSlug((string) ($data['parent'] ?? ''));
            if ($parentHint !== '') {
                $parentFile = self::findFilePath($parentHint);
                if ($parentFile !== null) {
                    $dir = self::pageDirFromFile($parentFile) . '/' . $slug;
                } else {
                    $dir = dirname($filePath) . '/' . $slug;
                }
            } else {
                $dir = self::PAGES_DIR . $slug;
            }
        }

        if ($slug === '') {
            return;
        }

        $target = $dir . '/' . self::CONTENT_FILE;
        if (is_file($target)) {
            // Already have markdown; drop legacy json if different path
            if (realpath($filePath) !== realpath($target)) {
                @unlink($filePath);
            }
            return;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log("DatabaseManager migrate: cannot create {$dir}");
            return;
        }

        $title = trim((string) ($data['title'] ?? $slug));
        $htmlOrMd = (string) ($data['content'] ?? '');
        $body = Markdown::htmlToMarkdown($htmlOrMd);
        $doc = Markdown::buildDocument($title, $body);

        if (file_put_contents($target, $doc, LOCK_EX) === false) {
            error_log("DatabaseManager migrate: cannot write {$target}");
            return;
        }
        @chmod($target, 0644);

        if (realpath($filePath) !== realpath($target)) {
            @unlink($filePath);
        }
    }

    public static function pageDirFromFile(string $pageFilePath): string
    {
        return dirname(str_replace('\\', '/', $pageFilePath));
    }

    public static function pathForPage(string $slug, string $parent = ''): ?string
    {
        $slug = self::sanitizeSlug($slug);
        $parent = self::sanitizeSlug($parent);
        if ($slug === '') {
            return null;
        }

        if ($parent === '') {
            return self::PAGES_DIR . $slug . '/' . self::CONTENT_FILE;
        }

        $parentFile = self::findFilePath($parent);
        if ($parentFile === null) {
            return null;
        }

        return self::pageDirFromFile($parentFile) . '/' . $slug . '/' . self::CONTENT_FILE;
    }

    public static function findFilePath(string $slug): ?string
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }
        $index = self::pathIndex();
        return $index[$slug] ?? null;
    }

    public static function parentFromPath(string $filePath): string
    {
        $filePath = str_replace('\\', '/', $filePath);
        $pages = str_replace('\\', '/', rtrim(self::PAGES_DIR, '/')) . '/';
        if (!str_starts_with($filePath, $pages)) {
            return '';
        }
        $rel = substr($filePath, strlen($pages));
        if (str_ends_with($rel, '/' . self::CONTENT_FILE)) {
            $rel = substr($rel, 0, -strlen('/' . self::CONTENT_FILE));
        }
        if ($rel === '' || !str_contains($rel, '/')) {
            return '';
        }
        return self::sanitizeSlug(basename(dirname($rel)));
    }

    /**
     * @return array{title: string, slug: string, content: string, parent: string, updated_at?: string}|null
     *         content = markdown body (without leading # title)
     */
    public static function getPageBySlug(string $slug): ?array
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        if (array_key_exists($slug, self::$pageCache)) {
            /** @var array|null $cached */
            $cached = self::$pageCache[$slug];
            return $cached;
        }

        $filePath = self::findFilePath($slug);
        if ($filePath === null) {
            self::$pageCache[$slug] = null;
            return null;
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            self::$pageCache[$slug] = null;
            return null;
        }

        $parsed = Markdown::parseDocument($raw);
        $title = $parsed['title'] !== '' ? $parsed['title'] : $slug;
        $body = $parsed['body'];

        $out = [
            'title' => $title,
            'slug' => $slug,
            'parent' => self::parentFromPath($filePath),
            'content' => $body,
        ];
        $mtime = @filemtime($filePath);
        if ($mtime) {
            $out['updated_at'] = date('c', $mtime);
        }
        self::$pageCache[$slug] = $out;
        return $out;
    }

    /**
     * Absolute directory for a page’s content.md and relative media.
     */
    public static function getPageDirectory(string $slug): ?string
    {
        $file = self::findFilePath($slug);
        return $file !== null ? self::pageDirFromFile($file) : null;
    }

    /**
     * @return string[]
     */
    public static function getAllSlugs(): array
    {
        $slugs = array_keys(self::pathIndex());
        sort($slugs);
        return $slugs;
    }

    /**
     * @return list<array{title: string, slug: string, content: string, parent: string, updated_at?: string}>
     */
    public static function getAllPages(): array
    {
        $pages = [];
        foreach (self::getAllSlugs() as $slug) {
            $page = self::getPageBySlug($slug);
            if ($page !== null) {
                $pages[] = $page;
            }
        }
        return $pages;
    }

    /**
     * @return list<array{title: string, slug: string, content: string, parent: string, updated_at?: string}>
     */
    public static function getTopLevelPages(): array
    {
        $top = array_values(array_filter(
            self::getAllPages(),
            static fn(array $p): bool => ($p['parent'] ?? '') === ''
        ));
        usort($top, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        return $top;
    }

    /**
     * @return list<array{title: string, slug: string, content: string, parent: string, updated_at?: string}>
     */
    public static function getChildPages(string $parentSlug): array
    {
        $parentSlug = self::sanitizeSlug($parentSlug);
        if ($parentSlug === '') {
            return [];
        }
        $parentFile = self::findFilePath($parentSlug);
        if ($parentFile === null) {
            return [];
        }
        $dir = self::pageDirFromFile($parentFile);
        $children = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === self::CONTENT_FILE
                || $name === self::ORDER_FILE || str_starts_with($name, '.')) {
                continue;
            }
            $childDir = $dir . '/' . $name;
            if (!is_dir($childDir) || !is_file($childDir . '/' . self::CONTENT_FILE)) {
                continue;
            }
            $slug = self::sanitizeSlug($name);
            $page = self::getPageBySlug($slug);
            if ($page !== null && ($page['parent'] ?? '') === $parentSlug) {
                $children[] = $page;
            }
        }
        return self::sortByOrder($children, $parentSlug);
    }

    /**
     * Read sibling order for a parent (empty string = top-level).
     *
     * @return string[] ordered slugs (may omit unknown; extras appended alphabetically)
     */
    public static function getOrder(string $parentSlug = ''): array
    {
        $parentSlug = self::sanitizeSlug($parentSlug);
        $dir = $parentSlug === ''
            ? rtrim(self::PAGES_DIR, '/\\')
            : self::getPageDirectory($parentSlug);
        if ($dir === null || !is_dir($dir)) {
            return [];
        }
        $path = $dir . '/' . self::ORDER_FILE;
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $s = self::sanitizeSlug((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Save sibling order for a parent (empty string = top-level).
     *
     * @param string[] $slugs
     */
    public static function saveOrder(string $parentSlug, array $slugs): bool
    {
        $parentSlug = self::sanitizeSlug($parentSlug);
        $dir = $parentSlug === ''
            ? rtrim(self::PAGES_DIR, '/\\')
            : self::getPageDirectory($parentSlug);
        if ($dir === null || !is_dir($dir)) {
            return false;
        }

        $clean = [];
        foreach ($slugs as $s) {
            $s = self::sanitizeSlug((string) $s);
            if ($s !== '' && !in_array($s, $clean, true)) {
                $clean[] = $s;
            }
        }

        $path = $dir . '/' . self::ORDER_FILE;
        $json = json_encode(array_values($clean), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        @chmod($path, 0644);
        self::invalidateCache();
        return true;
    }

    /**
     * Move a page up/down among its siblings.
     *
     * @param 'up'|'down' $direction
     */
    public static function reorderSibling(string $slug, string $direction): bool
    {
        $slug = self::sanitizeSlug($slug);
        $page = self::getPageBySlug($slug);
        if ($page === null) {
            return false;
        }
        $parent = (string) ($page['parent'] ?? '');
        $siblings = $parent === ''
            ? array_values(array_filter(
                self::getAllPages(),
                static fn(array $p): bool => ($p['parent'] ?? '') === ''
            ))
            : self::getChildPages($parent);

        // Already ordered via getChildPages/getPageTree logic for top-level:
        if ($parent === '') {
            $siblings = self::sortByOrder($siblings, '');
        }

        $order = array_map(static fn(array $p): string => (string) $p['slug'], $siblings);
        $idx = array_search($slug, $order, true);
        if ($idx === false) {
            return false;
        }
        $swap = $direction === 'up' ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= count($order)) {
            return false;
        }
        [$order[$idx], $order[$swap]] = [$order[$swap], $order[$idx]];
        return self::saveOrder($parent, $order);
    }

    /**
     * @param list<array{title: string, slug: string, parent?: string}> $pages
     * @return list<array{title: string, slug: string, parent?: string}>
     */
    private static function sortByOrder(array $pages, string $parentSlug): array
    {
        if ($pages === []) {
            return [];
        }
        $order = self::getOrder($parentSlug);
        $bySlug = [];
        foreach ($pages as $p) {
            $bySlug[(string) $p['slug']] = $p;
        }

        $sorted = [];
        foreach ($order as $s) {
            if (isset($bySlug[$s])) {
                $sorted[] = $bySlug[$s];
                unset($bySlug[$s]);
            }
        }
        // Remaining: alpha by title (stable default)
        $rest = array_values($bySlug);
        usort($rest, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        return array_merge($sorted, $rest);
    }

    /**
     * @return list<array{title: string, slug: string, content: string, parent: string, updated_at?: string}>
     */
    public static function getAncestors(string $slug): array
    {
        $chain = [];
        $seen = [];
        $current = self::sanitizeSlug($slug);
        $guard = 0;
        while ($guard++ < 50) {
            $page = self::getPageBySlug($current);
            if ($page === null) {
                break;
            }
            $parent = (string) ($page['parent'] ?? '');
            if ($parent === '' || isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $parentPage = self::getPageBySlug($parent);
            if ($parentPage === null) {
                break;
            }
            array_unshift($chain, $parentPage);
            $current = $parent;
        }
        return $chain;
    }

    public static function isAncestorOf(string $possibleAncestor, string $slug): bool
    {
        $possibleAncestor = self::sanitizeSlug($possibleAncestor);
        $slug = self::sanitizeSlug($slug);
        if ($possibleAncestor === '' || $slug === '') {
            return false;
        }
        if ($possibleAncestor === $slug) {
            return true;
        }
        foreach (self::getAncestors($slug) as $a) {
            if (($a['slug'] ?? '') === $possibleAncestor) {
                return true;
            }
        }
        return false;
    }

    public static function isDescendantOf(string $possibleDescendant, string $slug): bool
    {
        return self::isAncestorOf($slug, $possibleDescendant);
    }

    /**
     * @return list<array{title: string, slug: string, content: string, parent: string, updated_at?: string, children: list<array>}>
     */
    public static function getPageTree(): array
    {
        $bySlug = [];
        foreach (self::getAllPages() as $page) {
            $slug = (string) ($page['slug'] ?? '');
            if ($slug === '' || isset($bySlug[$slug])) {
                continue;
            }
            $bySlug[$slug] = $page;
        }

        $childSlugs = [];
        foreach ($bySlug as $slug => $page) {
            $parent = (string) ($page['parent'] ?? '');
            if ($parent !== '' && !isset($bySlug[$parent])) {
                $parent = '';
                $bySlug[$slug]['parent'] = '';
            }
            if ($parent === $slug) {
                $parent = '';
                $bySlug[$slug]['parent'] = '';
            }
            $childSlugs[$parent][] = $slug;
        }
        foreach ($childSlugs as $p => $list) {
            $childSlugs[$p] = array_values(array_unique($list));
        }

        $build = static function (string $parentSlug) use (&$build, $bySlug, $childSlugs): array {
            $nodes = [];
            foreach ($childSlugs[$parentSlug] ?? [] as $slug) {
                if (isset($bySlug[$slug])) {
                    $nodes[] = $bySlug[$slug];
                }
            }
            $nodes = self::sortByOrder($nodes, $parentSlug);
            $out = [];
            $seen = [];
            foreach ($nodes as $node) {
                $s = (string) $node['slug'];
                if (isset($seen[$s])) {
                    continue;
                }
                $seen[$s] = true;
                $node['children'] = $build($s);
                $out[] = $node;
            }
            return $out;
        };

        return $build('');
    }

    /**
     * @return list<array{title: string, slug: string, content: string, parent: string, label: string, depth: int, updated_at?: string}>
     */
    public static function getParentOptions(?string $forSlug = null): array
    {
        $forSlug = $forSlug !== null ? self::sanitizeSlug($forSlug) : '';
        $options = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$options, $forSlug): void {
            foreach ($nodes as $node) {
                $slug = (string) ($node['slug'] ?? '');
                // Skip self and descendants (would create cycles)
                if ($forSlug !== '' && self::isDescendantOf($slug, $forSlug)) {
                    continue;
                }
                $title = (string) ($node['title'] ?? $slug);
                $opt = $node;
                unset($opt['children']);
                $opt['depth'] = $depth;
                $opt['label'] = ($depth > 0 ? str_repeat('— ', $depth) : '') . $title;
                $options[] = $opt;
                if (!empty($node['children'])) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };
        $walk(self::getPageTree(), 0);
        return $options;
    }

    /**
     * @param array{title?: string, slug: string, content: string, parent?: string} $data
     *        content = markdown body (not including # title line)
     */
    public static function savePage(array $data): bool
    {
        self::migrateLegacyLayout();

        $slug = self::sanitizeSlug((string) ($data['slug'] ?? ''));
        $body = (string) ($data['content'] ?? '');
        $title = trim((string) ($data['title'] ?? ''));
        $parent = self::sanitizeSlug((string) ($data['parent'] ?? ''));

        if ($slug === '') {
            error_log('DatabaseManager: save failed — missing slug.');
            return false;
        }

        // Allow empty body (title-only page)
        if ($title === '') {
            $title = $slug;
        }

        if ($slug === 'home') {
            $parent = '';
        }
        if ($parent === $slug) {
            return false;
        }
        if ($parent !== '') {
            if (self::getPageBySlug($parent) === null) {
                return false;
            }
            if (self::isDescendantOf($parent, $slug)) {
                return false;
            }
        }

        if (!is_dir(self::PAGES_DIR) && !mkdir(self::PAGES_DIR, 0755, true) && !is_dir(self::PAGES_DIR)) {
            return false;
        }

        $oldPath = self::findFilePath($slug);
        $oldDir = $oldPath !== null ? self::pageDirFromFile($oldPath) : null;

        $newPath = self::pathForPage($slug, $parent);
        if ($newPath === null) {
            return false;
        }
        $newDir = self::pageDirFromFile($newPath);

        $moving = $oldDir !== null
            && (realpath($oldDir) ?: $oldDir) !== (realpath($newDir) ?: $newDir);

        if ($moving && $oldDir !== null && is_dir($oldDir)) {
            $parentOfNew = dirname($newDir);
            if (!is_dir($parentOfNew) && !mkdir($parentOfNew, 0755, true) && !is_dir($parentOfNew)) {
                return false;
            }
            if (is_dir($newDir)) {
                self::moveDirectoryContents($oldDir, $newDir);
                self::removeDirRecursiveIfSafe($oldDir);
            } else {
                if (!@rename($oldDir, $newDir)) {
                    if (!self::copyDirectory($oldDir, $newDir)) {
                        return false;
                    }
                    self::removeDirRecursiveIfSafe($oldDir);
                }
            }
        } elseif (!is_dir($newDir) && !mkdir($newDir, 0755, true) && !is_dir($newDir)) {
            return false;
        }

        $body = Markdown::relativizeMediaPaths($body, $slug);
        $doc = Markdown::buildDocument($title, $body);

        if (file_put_contents($newPath, $doc, LOCK_EX) === false) {
            return false;
        }
        @chmod($newPath, 0644);
        @unlink($newDir . '/' . self::LEGACY_JSON);

        self::removeStaleCopies($slug, $newPath);
        self::invalidateCache();
        return true;
    }

    public static function deletePage(string $slug): bool
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '' || $slug === 'home') {
            return false;
        }
        $page = self::getPageBySlug($slug);
        if ($page === null) {
            return false;
        }
        $promoteTo = (string) ($page['parent'] ?? '');
        $filePath = self::findFilePath($slug);
        if ($filePath === null) {
            return false;
        }
        $pageDir = self::pageDirFromFile($filePath);

        foreach (self::getChildPages($slug) as $child) {
            $child['parent'] = $promoteTo;
            self::savePage($child);
        }

        if (is_dir($pageDir)) {
            self::removeDirRecursiveIfSafe($pageDir);
            self::invalidateCache();
            return true;
        }
        return false;
    }

    public static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    /**
     * Resolve a relative media file under a page directory (no traversal).
     */
    public static function resolveMediaFile(string $slug, string $relativeFile): ?string
    {
        self::migrateLegacyLayout();
        $slug = self::sanitizeSlug($slug);
        $relativeFile = str_replace('\\', '/', $relativeFile);
        $relativeFile = ltrim($relativeFile, '/');
        if ($slug === '' || $relativeFile === '' || str_contains($relativeFile, '..')) {
            return null;
        }
        // only simple relative segments
        if (!preg_match('#^[A-Za-z0-9._\-]+(?:/[A-Za-z0-9._\-]+)*$#', $relativeFile)) {
            return null;
        }
        // block reading content.md as "media"
        if (basename($relativeFile) === self::CONTENT_FILE || basename($relativeFile) === self::LEGACY_JSON) {
            return null;
        }

        $dir = self::getPageDirectory($slug);
        if ($dir === null) {
            return null;
        }
        $full = $dir . '/' . $relativeFile;
        $real = realpath($full);
        $dirReal = realpath($dir);
        if ($real === false || $dirReal === false || !is_file($real)) {
            return null;
        }
        if (!str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR) && $real !== $dirReal) {
            return null;
        }
        return $real;
    }

    /** @return list<string> */
    private static function listPageFiles(): array
    {
        return array_values(self::pathIndex());
    }

    private static function removeStaleCopies(string $slug, string $keepPath): void
    {
        $slug = self::sanitizeSlug($slug);
        $keepReal = realpath($keepPath) ?: $keepPath;
        foreach (self::listPageFiles() as $path) {
            if (self::sanitizeSlug(basename(dirname($path))) !== $slug) {
                continue;
            }
            $real = realpath($path) ?: $path;
            if ($real !== $keepReal) {
                self::removeDirRecursiveIfSafe(dirname($path));
            }
        }
    }

    private static function moveDirectoryContents(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $src = $from . '/' . $name;
            $dst = $to . '/' . $name;
            if (is_dir($src)) {
                self::moveDirectoryContents($src, $dst);
                @rmdir($src);
            } else {
                if (is_file($dst)) {
                    @unlink($dst);
                }
                @rename($src, $dst) || (copy($src, $dst) && unlink($src));
            }
        }
    }

    private static function copyDirectory(string $from, string $to): bool
    {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            return false;
        }
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $src = $from . '/' . $name;
            $dst = $to . '/' . $name;
            if (is_dir($src)) {
                if (!self::copyDirectory($src, $dst)) {
                    return false;
                }
            } elseif (!copy($src, $dst)) {
                return false;
            }
        }
        return true;
    }

    private static function removeDirRecursiveIfSafe(string $dir): void
    {
        $dirReal = realpath($dir);
        $pagesReal = realpath(self::PAGES_DIR);
        if ($dirReal === false || $pagesReal === false) {
            return;
        }
        if ($dirReal === $pagesReal || !str_starts_with($dirReal, $pagesReal . DIRECTORY_SEPARATOR)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dirReal);
    }
}
