<?php

declare(strict_types=1);

namespace WikiApp\Core;

/**
 * Export / import the entire pages volume (markdown, media, order files, branding)
 * as a portable ZIP backup.
 *
 * Archive layout:
 *   wikiflip-backup/manifest.json
 *   wikiflip-backup/pages/…          ← full pages tree (.site, content.md, media, .order.json)
 */
final class ContentBackup
{
    public const FORMAT = 'wikiflip-content-backup';
    public const VERSION = 1;

    /** Soft limit on import archive size (bytes). */
    public const MAX_IMPORT_BYTES = 100 * 1024 * 1024;

    public static function isAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Build a zip of the pages directory. Returns absolute path to a temp file.
     * Caller must @unlink() after streaming.
     *
     * @throws \RuntimeException
     */
    public static function exportToTempFile(): string
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('ZipArchive is not available on this PHP install.');
        }

        $pagesDir = rtrim(DatabaseManager::getPagesDir(), '/\\');
        if (!is_dir($pagesDir)) {
            throw new \RuntimeException('Pages directory is missing.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wikiflip-export-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temporary file.');
        }
        // tempnam creates a file; ZipArchive needs .zip path
        $zipPath = $tmp . '.zip';
        @unlink($tmp);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP archive.');
        }

        $rootPrefix = 'wikiflip-backup';
        $manifest = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'site_title' => SiteSettings::siteTitle(),
            'generator' => 'WikiFlip',
        ];
        $zip->addFromString(
            $rootPrefix . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $pagesPrefix = $rootPrefix . '/pages';
        self::addDirectoryToZip($zip, $pagesDir, $pagesPrefix);

        $zip->close();

        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            @unlink($zipPath);
            throw new \RuntimeException('Export produced an empty archive.');
        }

        return $zipPath;
    }

    /**
     * Suggested download filename for the browser.
     */
    public static function downloadFilename(): string
    {
        $stamp = gmdate('Ymd-His');
        $title = SiteSettings::siteTitle();
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)) ?: 'wikiflip';
        $slug = trim((string) $slug, '-') ?: 'wikiflip';
        return $slug . '-backup-' . $stamp . '.zip';
    }

    /**
     * Import a backup ZIP into the pages directory.
     *
     * @param 'replace'|'merge' $mode
     * @return array{ok: bool, message: string, files?: int, mode?: string}
     */
    public static function importFromZipFile(string $zipPath, string $mode = 'replace'): array
    {
        if (!self::isAvailable()) {
            return ['ok' => false, 'message' => 'ZipArchive is not available on this PHP install.'];
        }

        $mode = $mode === 'merge' ? 'merge' : 'replace';

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            return ['ok' => false, 'message' => 'Uploaded file is not readable.'];
        }

        $size = filesize($zipPath);
        if ($size === false || $size < 22) {
            return ['ok' => false, 'message' => 'Uploaded file is empty or not a valid ZIP.'];
        }
        if ($size > self::MAX_IMPORT_BYTES) {
            $mb = (int) (self::MAX_IMPORT_BYTES / 1024 / 1024);
            return ['ok' => false, 'message' => "Archive is too large (max {$mb} MB)."];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'Could not open ZIP archive. Is it a valid .zip file?'];
        }

        $pagesRootInZip = self::detectPagesRootInZip($zip);
        if ($pagesRootInZip === null) {
            $zip->close();
            return [
                'ok' => false,
                'message' => 'ZIP does not look like a WikiFlip content backup (no pages/content.md found).',
            ];
        }

        $extractDir = sys_get_temp_dir() . '/wikiflip-import-' . bin2hex(random_bytes(8));
        if (!mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $zip->close();
            return ['ok' => false, 'message' => 'Could not create temporary extract directory.'];
        }

        try {
            $fileCount = self::extractPagesEntries($zip, $pagesRootInZip, $extractDir);
            $zip->close();

            if ($fileCount === 0) {
                return ['ok' => false, 'message' => 'Archive contained no extractable page files.'];
            }

            // Require at least one content.md
            if (!self::directoryContainsContentMd($extractDir)) {
                return ['ok' => false, 'message' => 'Archive has no content.md files after extraction.'];
            }

            $pagesDir = rtrim(DatabaseManager::getPagesDir(), '/\\');
            if (!is_dir($pagesDir) && !mkdir($pagesDir, 0755, true) && !is_dir($pagesDir)) {
                return ['ok' => false, 'message' => 'Pages directory is missing and could not be created.'];
            }

            if ($mode === 'replace') {
                self::wipeDirectoryContents($pagesDir);
            }

            self::copyTree($extractDir, $pagesDir);
            DatabaseManager::invalidateCache();
            SiteSettings::clearCache();

            return [
                'ok' => true,
                'message' => $mode === 'replace'
                    ? "Import complete (replaced site). Restored {$fileCount} files."
                    : "Import complete (merged). Applied {$fileCount} files.",
                'files' => $fileCount,
                'mode' => $mode,
            ];
        } catch (\Throwable $e) {
            if ($zip instanceof \ZipArchive) {
                @$zip->close();
            }
            return ['ok' => false, 'message' => 'Import failed: ' . $e->getMessage()];
        } finally {
            self::removeTree($extractDir);
        }
    }

    /**
     * Add all files under $dir into the zip with $zipPrefix as path prefix.
     */
    private static function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipPrefix): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $zipPrefix = rtrim(str_replace('\\', '/', $zipPrefix), '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = str_replace('\\', '/', $fileInfo->getPathname());
            $rel = substr($path, strlen($dir) + 1);
            if ($rel === false || $rel === '') {
                continue;
            }
            // Skip junk
            if (str_contains($rel, '/.DS_Store') || str_ends_with($rel, '/.DS_Store') || $rel === '.DS_Store') {
                continue;
            }

            $entry = $zipPrefix . '/' . $rel;
            if ($fileInfo->isDir()) {
                $zip->addEmptyDir($entry);
            } elseif ($fileInfo->isFile() && $fileInfo->isReadable()) {
                $zip->addFile($path, $entry);
            }
        }
    }

    /**
     * Find the pages root path prefix inside the zip (with trailing semantics as used in entry names).
     * Returns "" if zip root IS the pages tree, "pages" if pages/ at root, "wikiflip-backup/pages", etc.
     */
    private static function detectPagesRootInZip(\ZipArchive $zip): ?string
    {
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $name = ltrim($name, '/');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_contains($name, '..')) {
                continue;
            }

            // Prefer …/pages/…/content.md
            if (preg_match('#^(?:(.+)/)?pages/(.+/)?content\.md$#', $name, $m)) {
                $prefix = isset($m[1]) && $m[1] !== '' ? $m[1] . '/pages' : 'pages';
                $candidates[$prefix] = ($candidates[$prefix] ?? 0) + 1;
                continue;
            }

            // Bare tree: home/content.md at archive root
            if (preg_match('#^([^/]+)/content\.md$#', $name) || $name === 'content.md') {
                $candidates[''] = ($candidates[''] ?? 0) + 1;
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Prefer explicit pages/ roots with most content.md hits
        arsort($candidates);
        foreach (array_keys($candidates) as $prefix) {
            if ($prefix !== '' && str_ends_with($prefix, 'pages')) {
                return $prefix;
            }
        }

        return array_key_first($candidates);
    }

    /**
     * Extract only entries under the detected pages root into $destDir (flattened to pages contents).
     *
     * @return int number of files written
     */
    private static function extractPagesEntries(\ZipArchive $zip, string $pagesRootInZip, string $destDir): int
    {
        $destDir = rtrim(str_replace('\\', '/', $destDir), '/');
        $prefix = $pagesRootInZip === '' ? '' : rtrim(str_replace('\\', '/', $pagesRootInZip), '/') . '/';
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $name = ltrim($name, '/');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, "\0")) {
                continue;
            }

            if ($prefix !== '') {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }
                $rel = substr($name, strlen($prefix));
            } else {
                // Skip manifest at root of bare archives
                if ($name === 'manifest.json') {
                    continue;
                }
                $rel = $name;
            }

            if ($rel === false || $rel === '' || str_contains($rel, '..')) {
                continue;
            }
            // Skip macOS resource forks
            if (str_starts_with($rel, '__MACOSX/') || str_contains($rel, '/__MACOSX/')) {
                continue;
            }
            if (basename($rel) === '.DS_Store') {
                continue;
            }

            $target = $destDir . '/' . $rel;
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Could not create directory for ' . $rel);
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                throw new \RuntimeException('Could not write ' . $rel);
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
            @chmod($target, 0644);
            $count++;
        }

        return $count;
    }

    private static function directoryContainsContentMd(string $dir): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isFile() && $fileInfo->getFilename() === DatabaseManager::CONTENT_FILE) {
                return true;
            }
        }
        return false;
    }

    /** Delete everything inside $dir but keep $dir itself (volume mount friendly). */
    private static function wipeDirectoryContents(string $dir): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            self::removeTree($dir . '/' . $name);
        }
    }

    private static function copyTree(string $src, string $dest): void
    {
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $dest = rtrim(str_replace('\\', '/', $dest), '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = str_replace('\\', '/', $fileInfo->getPathname());
            $rel = substr($path, strlen($src) + 1);
            if ($rel === false || $rel === '') {
                continue;
            }
            $target = $dest . '/' . $rel;
            if ($fileInfo->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new \RuntimeException('Could not create ' . $rel);
                }
            } elseif ($fileInfo->isFile()) {
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new \RuntimeException('Could not create directory for ' . $rel);
                }
                if (!@copy($path, $target)) {
                    throw new \RuntimeException('Could not copy ' . $rel);
                }
                @chmod($target, 0644);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $p = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($path);
    }
}
