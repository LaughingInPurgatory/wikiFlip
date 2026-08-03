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
 *
 * Import also accepts .tar.gz / .tar from older builds.
 */
final class ContentBackup
{
    public const FORMAT = 'wikiflip-content-backup';
    public const VERSION = 1;

    /** Soft limit on import archive size (bytes). */
    public const MAX_IMPORT_BYTES = 100 * 1024 * 1024;

    private const SESSION_EXPORT_KEY = 'wikiflip_pending_export';

    public static function isAvailable(): bool
    {
        return class_exists(\ZipArchive::class) || class_exists(\PharData::class);
    }

    public static function canExport(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Build a ZIP of the pages directory. Returns absolute path to a temp file.
     * Caller must @unlink() after streaming (or use prepareDownload / takePendingDownload).
     *
     * @throws \RuntimeException
     */
    public static function exportToTempFile(): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive is not available.');
        }

        $pagesDir = rtrim(DatabaseManager::getPagesDir(), '/\\');
        if (!is_dir($pagesDir)) {
            throw new \RuntimeException('Pages directory is missing.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wikiflip-export-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temporary file.');
        }
        $zipPath = $tmp . '.zip';
        @unlink($tmp);

        $zip = new \ZipArchive();
        $flags = \ZipArchive::CREATE | \ZipArchive::OVERWRITE;
        if ($zip->open($zipPath, $flags) !== true) {
            throw new \RuntimeException('Could not create ZIP archive.');
        }

        $rootPrefix = 'wikiflip-backup';
        $manifest = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'site_title' => SiteSettings::siteTitle(),
            'generator' => 'WikiFlip',
            'archive' => 'zip',
        ];
        $zip->addFromString(
            $rootPrefix . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        self::addDirectoryToZip($zip, $pagesDir, $rootPrefix . '/pages');

        // Prefer stored/deflated entries; close flushes central directory
        if (!$zip->close()) {
            @unlink($zipPath);
            throw new \RuntimeException('Failed to finalize ZIP archive.');
        }

        if (!is_file($zipPath) || filesize($zipPath) < 22) {
            @unlink($zipPath);
            throw new \RuntimeException('Export produced an empty archive.');
        }

        // Verify local PK signature
        $fh = fopen($zipPath, 'rb');
        if ($fh === false) {
            @unlink($zipPath);
            throw new \RuntimeException('Could not re-open export ZIP.');
        }
        $magic = fread($fh, 2);
        fclose($fh);
        if ($magic !== 'PK') {
            @unlink($zipPath);
            throw new \RuntimeException('Export file is not a valid ZIP.');
        }

        return $zipPath;
    }

    /**
     * Suggested download filename (ASCII-safe .zip).
     */
    public static function downloadFilename(): string
    {
        $stamp = gmdate('Ymd-His');
        $title = SiteSettings::siteTitle();
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)) ?: 'wikiflip';
        $slug = trim((string) $slug, '-') ?: 'wikiflip';
        // Keep name simple for Safari / Windows
        return $slug . '-backup-' . $stamp . '.zip';
    }

    /**
     * Build a ZIP and stash a one-time download token in the session.
     * Browser then GETs export.php?download=TOKEN so the file is a normal attachment.
     *
     * @return string opaque token
     * @throws \RuntimeException
     */
    public static function prepareDownload(): string
    {
        Auth::startSession();
        // Drop any previous pending export first
        self::discardPendingDownload();

        $path = self::exportToTempFile();
        $token = bin2hex(random_bytes(16));
        $filename = self::downloadFilename();

        $_SESSION[self::SESSION_EXPORT_KEY] = [
            'token' => $token,
            'path' => $path,
            'filename' => $filename,
            'created' => time(),
        ];

        return $token;
    }

    /**
     * Consume a one-time download token. Returns [path, filename] or null.
     *
     * @return array{path: string, filename: string}|null
     */
    public static function takePendingDownload(string $token): ?array
    {
        Auth::startSession();
        $pending = $_SESSION[self::SESSION_EXPORT_KEY] ?? null;
        unset($_SESSION[self::SESSION_EXPORT_KEY]);

        if (!is_array($pending)) {
            return null;
        }

        $expected = (string) ($pending['token'] ?? '');
        $path = (string) ($pending['path'] ?? '');
        $filename = (string) ($pending['filename'] ?? 'wikiflip-backup.zip');
        $created = (int) ($pending['created'] ?? 0);

        // 10-minute one-shot
        if ($expected === '' || !hash_equals($expected, $token) || $created < time() - 600) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            return null;
        }

        if ($path === '' || !is_file($path)) {
            return null;
        }

        if (!str_ends_with(strtolower($filename), '.zip')) {
            $filename .= '.zip';
        }

        return ['path' => $path, 'filename' => $filename];
    }

    public static function discardPendingDownload(): void
    {
        Auth::startSession();
        $pending = $_SESSION[self::SESSION_EXPORT_KEY] ?? null;
        unset($_SESSION[self::SESSION_EXPORT_KEY]);
        if (is_array($pending)) {
            $path = (string) ($pending['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Stream a local ZIP to the client with headers that keep it a .zip file
     * (not auto-expanded into a folder by Safari when possible).
     */
    public static function streamZipFile(string $path, string $filename): never
    {
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $size = filesize($path);
        if ($size === false || $size < 22) {
            @unlink($path);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Export file missing or empty.';
            exit;
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'wikiflip-backup.zip';
        if (!str_ends_with(strtolower($safe), '.zip')) {
            $safe .= '.zip';
        }

        // application/octet-stream + attachment reduces Safari “Open safe files” auto-unzip
        header('Content-Type: application/octet-stream');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . (string) $size);
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Description: File Transfer');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: public');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        // Hint that this is a download, not a navigable document
        header('X-Download-Options: noopen');

        $sent = readfile($path);
        @unlink($path);

        if ($sent === false) {
            // Headers may already be sent; best-effort
            exit;
        }
        exit;
    }

    /**
     * Import a backup archive (.zip preferred; .tar.gz / .tar also supported).
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
            return ['ok' => false, 'message' => 'Unrecognized archive. Use a .zip WikiFlip backup (or .tar.gz).'];
        }

        if ($kind === 'zip') {
            return self::importFromZipFile($archivePath, $mode);
        }

        return self::importFromTarFile($archivePath, $mode, $kind === 'tar.gz');
    }

    /**
     * @param 'replace'|'merge' $mode
     * @return array{ok: bool, message: string, files?: int, mode?: string}
     */
    public static function importFromZipFile(string $zipPath, string $mode = 'replace'): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'message' => 'ZipArchive is not available.'];
        }

        $mode = $mode === 'merge' ? 'merge' : 'replace';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'Could not open ZIP archive. Is it a valid .zip file?'];
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
                    $gzPhar->decompress();
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

            return self::applyExtractedPages($pagesSource, self::countFiles($pagesSource), $mode);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Import failed: ' . $e->getMessage()];
        } finally {
            self::removeTree($work);
        }
    }

    private static function findPagesRootOnDisk(string $extractRoot): ?string
    {
        $extractRoot = rtrim(str_replace('\\', '/', $extractRoot), '/');

        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isDir() && $fileInfo->getFilename() === 'pages') {
                $path = str_replace('\\', '/', $fileInfo->getPathname());
                if (self::directoryContainsContentMd($path)) {
                    $candidates[] = $path;
                }
            }
        }
        if ($candidates !== []) {
            usort($candidates, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));
            return $candidates[0];
        }

        if (self::directoryContainsContentMd($extractRoot)) {
            return $extractRoot;
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
        if (str_starts_with($magic, "\x1f\x8b")) {
            return 'tar.gz';
        }
        if (str_starts_with($magic, 'PK')) {
            return 'zip';
        }
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
            if ($rel === '.DS_Store' || str_ends_with($rel, '/.DS_Store')) {
                continue;
            }

            $entry = $zipPrefix . '/' . $rel;
            if ($fileInfo->isDir()) {
                $zip->addEmptyDir($entry);
            } elseif ($fileInfo->isFile() && $fileInfo->isReadable()) {
                // ZipArchive::FL_ENC_UTF_8 when available keeps paths clean
                if (defined('ZipArchive::FL_ENC_UTF_8')) {
                    $zip->addFile($path, $entry);
                    $idx = $zip->locateName($entry);
                    if ($idx !== false) {
                        $zip->setCompressionName($entry, \ZipArchive::CM_DEFLATE);
                    }
                } else {
                    $zip->addFile($path, $entry);
                }
            }
        }
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

    /**
     * @param list<string> $names
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
