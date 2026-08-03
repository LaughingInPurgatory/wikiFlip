<?php

declare(strict_types=1);

namespace WikiApp\Core;

/**
 * Export / import the entire pages volume (markdown, media, order files, branding)
 * as a portable tarball (.tar.gz).
 *
 * Archive layout:
 *   wikiflip-backup/manifest.json
 *   wikiflip-backup/pages/…          ← full pages tree (.site, content.md, media, .order.json)
 *
 * Import also accepts older .zip backups and bare pages/ trees inside archives.
 */
final class ContentBackup
{
    public const FORMAT = 'wikiflip-content-backup';
    public const VERSION = 1;

    /** Soft limit on import archive size (bytes). */
    public const MAX_IMPORT_BYTES = 100 * 1024 * 1024;

    public static function isAvailable(): bool
    {
        // PharData creates tar/tar.gz without needing ZipArchive
        return class_exists(\PharData::class) || class_exists(\ZipArchive::class);
    }

    public static function canExport(): bool
    {
        return class_exists(\PharData::class);
    }

    /**
     * Build a .tar.gz of the pages directory. Returns absolute path to a temp file.
     * Caller must @unlink() after streaming.
     *
     * @throws \RuntimeException
     */
    public static function exportToTempFile(): string
    {
        if (!class_exists(\PharData::class)) {
            throw new \RuntimeException('PHP PharData is not available (needed for tar.gz export).');
        }

        $pagesDir = rtrim(DatabaseManager::getPagesDir(), '/\\');
        if (!is_dir($pagesDir)) {
            throw new \RuntimeException('Pages directory is missing.');
        }

        $work = sys_get_temp_dir() . '/wikiflip-export-' . bin2hex(random_bytes(8));
        if (!mkdir($work, 0700, true) && !is_dir($work)) {
            throw new \RuntimeException('Could not create temporary export directory.');
        }

        $tarPath = $work . '/backup.tar';
        $gzPath = $tarPath . '.gz';

        try {
            // PharData refuses to overwrite existing archives
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
            if (is_file($gzPath)) {
                @unlink($gzPath);
            }

            $phar = new \PharData($tarPath);

            $rootPrefix = 'wikiflip-backup';
            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'exported_at' => gmdate('c'),
                'site_title' => SiteSettings::siteTitle(),
                'generator' => 'WikiFlip',
                'archive' => 'tar.gz',
            ];
            $phar->addFromString(
                $rootPrefix . '/manifest.json',
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            );

            self::addDirectoryToPhar($phar, $pagesDir, $rootPrefix . '/pages');

            // Compress to .tar.gz (creates backup.tar.gz alongside backup.tar)
            $phar->compress(\Phar::GZ);
            unset($phar);

            // Drop the uncompressed .tar
            @unlink($tarPath);

            if (!is_file($gzPath) || filesize($gzPath) === 0) {
                throw new \RuntimeException('Export produced an empty tarball.');
            }

            // Move out of work dir so we can delete the work folder
            $final = sys_get_temp_dir() . '/wikiflip-export-' . bin2hex(random_bytes(6)) . '.tar.gz';
            if (!@rename($gzPath, $final) && !@copy($gzPath, $final)) {
                throw new \RuntimeException('Could not finalize export tarball.');
            }
            @unlink($gzPath);

            return $final;
        } finally {
            self::removeTree($work);
        }
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
        return $slug . '-backup-' . $stamp . '.tar.gz';
    }

    /**
     * Import a backup archive (.tar.gz, .tar, or legacy .zip) into the pages directory.
     *
     * @param 'replace'|'merge' $mode
     * @return array{ok: bool, message: string, files?: int, mode?: string}
     */
    public static function importFromArchive(string $archivePath, string $mode = 'replace', ?string $originalName = null): array
    {
        $mode = $mode === 'merge' ? 'merge' : 'replace';

        if (!is_file($archivePath) || !is_readable($archivePath)) {
            return ['ok' => false, 'message' => 'Uploaded file is not readable.'];
        }

        $size = filesize($archivePath);
        if ($size === false || $size < 20) {
            return ['ok' => false, 'message' => 'Uploaded file is empty or too small to be a valid archive.'];
        }
        if ($size > self::MAX_IMPORT_BYTES) {
            $mb = (int) (self::MAX_IMPORT_BYTES / 1024 / 1024);
            return ['ok' => false, 'message' => "Archive is too large (max {$mb} MB)."];
        }

        $kind = self::detectArchiveKind($archivePath, $originalName);
        if ($kind === null) {
            return ['ok' => false, 'message' => 'Unrecognized archive. Use a .tar.gz (or .tar / legacy .zip) WikiFlip backup.'];
        }

        if ($kind === 'zip') {
            return self::importFromZipFile($archivePath, $mode);
        }

        return self::importFromTarFile($archivePath, $mode, $kind === 'tar.gz');
    }

    /**
     * @deprecated use importFromArchive()
     * @param 'replace'|'merge' $mode
     * @return array{ok: bool, message: string, files?: int, mode?: string}
     */
    public static function importFromZipFile(string $zipPath, string $mode = 'replace'): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'message' => 'ZipArchive is not available to read legacy .zip backups.'];
        }

        $mode = $mode === 'merge' ? 'merge' : 'replace';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'Could not open ZIP archive.'];
        }

        $pagesRoot = self::detectPagesRootFromNames(self::listZipEntryNames($zip));
        if ($pagesRoot === null) {
            $zip->close();
            return [
                'ok' => false,
                'message' => 'Archive does not look like a WikiFlip content backup (no pages/content.md found).',
            ];
        }

        $extractDir = sys_get_temp_dir() . '/wikiflip-import-' . bin2hex(random_bytes(8));
        if (!mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $zip->close();
            return ['ok' => false, 'message' => 'Could not create temporary extract directory.'];
        }

        try {
            $fileCount = self::extractZipPagesEntries($zip, $pagesRoot, $extractDir);
            $zip->close();
            return self::applyExtractedPages($extractDir, $fileCount, $mode);
        } catch (\Throwable $e) {
            @$zip->close();
            return ['ok' => false, 'message' => 'Import failed: ' . $e->getMessage()];
        } finally {
            self::removeTree($extractDir);
        }
    }

    /**
     * @param 'replace'|'merge' $mode
     * @return array{ok: bool, message: string, files?: int, mode?: string}
     */
    private static function importFromTarFile(string $path, string $mode, bool $gzipped): array
    {
        if (!class_exists(\PharData::class)) {
            return ['ok' => false, 'message' => 'PHP PharData is not available to read tar backups.'];
        }

        $work = sys_get_temp_dir() . '/wikiflip-import-' . bin2hex(random_bytes(8));
        if (!mkdir($work, 0700, true) && !is_dir($work)) {
            return ['ok' => false, 'message' => 'Could not create temporary extract directory.'];
        }

        try {
            $localArchive = $work . '/incoming' . ($gzipped ? '.tar.gz' : '.tar');
            if (!@copy($path, $localArchive)) {
                return ['ok' => false, 'message' => 'Could not stage uploaded archive.'];
            }

            $tarPath = $localArchive;
            if ($gzipped) {
                try {
                    $gzPhar = new \PharData($localArchive);
                    $gzPhar->decompress(); // creates incoming.tar next to .tar.gz
                    unset($gzPhar);
                } catch (\Throwable $e) {
                    return ['ok' => false, 'message' => 'Could not decompress .tar.gz: ' . $e->getMessage()];
                }
                $tarPath = $work . '/incoming.tar';
                if (!is_file($tarPath)) {
                    return ['ok' => false, 'message' => 'Could not decompress .tar.gz archive.'];
                }
            }

            $rawExtract = $work . '/raw';
            if (!mkdir($rawExtract, 0755, true) && !is_dir($rawExtract)) {
                return ['ok' => false, 'message' => 'Could not create extract target.'];
            }

            try {
                $phar = new \PharData($tarPath);
                $phar->extractTo($rawExtract, null, true);
                unset($phar);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => 'Could not extract tarball: ' . $e->getMessage()];
            }

            $pagesSource = self::findPagesRootOnDisk($rawExtract);
            if ($pagesSource === null) {
                return [
                    'ok' => false,
                    'message' => 'Archive does not look like a WikiFlip content backup (no pages/content.md found).',
                ];
            }

            $fileCount = self::countFiles($pagesSource);
            return self::applyExtractedPages($pagesSource, $fileCount, $mode);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Import failed: ' . $e->getMessage()];
        } finally {
            self::removeTree($work);
        }
    }

    /**
     * Locate the pages tree root after full extract (…/pages with content.md, or bare tree).
     */
    private static function findPagesRootOnDisk(string $extractRoot): ?string
    {
        $extractRoot = rtrim(str_replace('\\', '/', $extractRoot), '/');

        // Preferred: any …/pages directory that contains content.md somewhere under it
        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isDir()) {
                continue;
            }
            if ($fileInfo->getFilename() !== 'pages') {
                continue;
            }
            $path = str_replace('\\', '/', $fileInfo->getPathname());
            if (self::directoryContainsContentMd($path)) {
                $candidates[] = $path;
            }
        }
        if ($candidates !== []) {
            // Prefer deeper paths? Usually one. Take first.
            usort($candidates, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));
            return $candidates[0];
        }

        // Bare archive: extract root itself is the pages tree
        if (self::directoryContainsContentMd($extractRoot)) {
            return $extractRoot;
        }

        // Single top-level folder (e.g. wikiflip-backup without pages/ name)
        foreach (scandir($extractRoot) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $extractRoot . '/' . $name;
            if (is_dir($child) && self::directoryContainsContentMd($child) && !is_dir($child . '/pages')) {
                // only if it looks like pages (has home/ or any content.md)
                return $child;
            }
        }

        return null;
    }

    private static function countFiles(string $dir): int
    {
        $n = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isFile()) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return list<string>
     */
    private static function listZipEntryNames(\ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $name = ltrim($name, '/');
            if ($name !== '' && !str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private static function detectArchiveKind(string $path, ?string $originalName): ?string
    {
        $name = strtolower((string) ($originalName ?? basename($path)));
        if (str_ends_with($name, '.tar.gz') || str_ends_with($name, '.tgz')) {
            return 'tar.gz';
        }
        if (str_ends_with($name, '.tar')) {
            return 'tar';
        }
        if (str_ends_with($name, '.zip')) {
            return 'zip';
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $magic = fread($fh, 4);
        fclose($fh);
        if ($magic === false) {
            return null;
        }
        // gzip
        if (str_starts_with($magic, "\x1f\x8b")) {
            return 'tar.gz';
        }
        // zip
        if (str_starts_with($magic, 'PK')) {
            return 'zip';
        }
        // ustar tar often has "ustar" at offset 257 — check loosely
        $fh = fopen($path, 'rb');
        if ($fh !== false) {
            fseek($fh, 257);
            $ustar = fread($fh, 5);
            fclose($fh);
            if ($ustar === 'ustar') {
                return 'tar';
            }
        }
        return null;
    }

    private static function addDirectoryToPhar(\PharData $phar, string $dir, string $prefix): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $prefix = rtrim(str_replace('\\', '/', $prefix), '/');

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
            if ($rel === '.DS_Store' || str_ends_with($rel, '/.DS_Store')) {
                continue;
            }
            $entry = $prefix . '/' . $rel;
            if ($fileInfo->isDir()) {
                // PharData creates parent dirs when adding files
                continue;
            }
            if ($fileInfo->isFile() && $fileInfo->isReadable()) {
                $phar->addFile($path, $entry);
            }
        }
    }

    /**
     * @param list<string> $names archive-relative file paths
     */
    private static function detectPagesRootFromNames(array $names): ?string
    {
        $candidates = [];

        foreach ($names as $name) {
            $name = ltrim(str_replace('\\', '/', $name), '/');
            if ($name === '' || str_contains($name, '..')) {
                continue;
            }

            if (preg_match('#^(?:(.+)/)?pages/(?:.+/)?content\.md$#', $name, $m)) {
                $prefix = isset($m[1]) && $m[1] !== '' ? $m[1] . '/pages' : 'pages';
                $candidates[$prefix] = ($candidates[$prefix] ?? 0) + 1;
                continue;
            }

            if (preg_match('#^([^/]+)/content\.md$#', $name) || $name === 'content.md') {
                $candidates[''] = ($candidates[''] ?? 0) + 1;
            }
        }

        if ($candidates === []) {
            return null;
        }

        arsort($candidates);
        foreach (array_keys($candidates) as $prefix) {
            if ($prefix !== '' && str_ends_with($prefix, 'pages')) {
                return $prefix;
            }
        }

        return array_key_first($candidates);
    }

    private static function extractZipPagesEntries(\ZipArchive $zip, string $pagesRootInZip, string $destDir): int
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
                if ($name === 'manifest.json') {
                    continue;
                }
                $rel = $name;
            }

            if ($rel === false || $rel === '' || str_contains($rel, '..')) {
                continue;
            }
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

    /**
     * @param 'replace'|'merge' $mode
     * @return array{ok: bool, message: string, files?: int, mode?: string}
     */
    private static function applyExtractedPages(string $extractDir, int $fileCount, string $mode): array
    {
        if ($fileCount === 0) {
            return ['ok' => false, 'message' => 'Archive contained no extractable page files.'];
        }

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
