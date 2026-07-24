<?php

declare(strict_types=1);

namespace WikiApp\Core;

/**
 * Site branding stored under pages/.site/ so it lives on the Docker pages volume.
 *
 *   pages/.site/settings.json   { "site_title": "My Wiki" }
 *   pages/.site/logo.ext        custom logo (png/jpg/webp/gif/svg)
 */
final class SiteSettings
{
    private const DIR_NAME = '.site';
    private const SETTINGS_FILE = 'settings.json';

    /** @var array{site_title: string, logo_file: string|null}|null */
    private static ?array $cache = null;

    public static function dir(): string
    {
        return rtrim(DatabaseManager::getPagesDir(), '/\\') . '/' . self::DIR_NAME;
    }

    /**
     * @return array{site_title: string, logo_file: string|null}
     */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defaults = [
            'site_title' => 'WikiFlip',
            'logo_file' => null,
        ];

        $path = self::dir() . '/' . self::SETTINGS_FILE;
        if (!is_file($path)) {
            self::$cache = $defaults;
            return self::$cache;
        }

        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            self::$cache = $defaults;
            return self::$cache;
        }

        $title = trim((string) ($data['site_title'] ?? 'WikiFlip'));
        if ($title === '') {
            $title = 'WikiFlip';
        }

        $logo = isset($data['logo_file']) ? (string) $data['logo_file'] : null;
        if ($logo !== null && $logo !== '') {
            $logo = basename(str_replace('\\', '/', $logo));
            if (!is_file(self::dir() . '/' . $logo)) {
                $logo = null;
            }
        } else {
            $logo = null;
        }

        self::$cache = [
            'site_title' => $title,
            'logo_file' => $logo,
        ];
        return self::$cache;
    }

    public static function siteTitle(): string
    {
        return self::get()['site_title'];
    }

    /**
     * Public URL for the logo (custom or default /logo.png).
     */
    public static function logoUrl(): string
    {
        $logo = self::get()['logo_file'];
        if ($logo !== null && $logo !== '') {
            return url('media.php?slug=_site&file=' . rawurlencode($logo));
        }
        return url('logo.png');
    }

    /**
     * Absolute filesystem path for a branding logo file, or null.
     */
    public static function logoPath(): ?string
    {
        $logo = self::get()['logo_file'];
        if ($logo === null || $logo === '') {
            return null;
        }
        $path = self::dir() . '/' . $logo;
        return is_file($path) ? $path : null;
    }

    public static function saveTitle(string $title): bool
    {
        $title = trim(str_replace(["\r", "\n"], ' ', $title));
        if ($title === '') {
            $title = 'WikiFlip';
        }
        $current = self::get();
        return self::writeSettings([
            'site_title' => $title,
            'logo_file' => $current['logo_file'],
        ]);
    }

    /**
     * Store uploaded logo; $tmpPath is a validated uploaded temp file.
     */
    public static function saveLogoFromUpload(string $tmpPath, string $originalName, string $mime): bool
    {
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        if (!isset($allowed[$mime])) {
            return false;
        }
        if ($mime !== 'image/svg+xml' && @getimagesize($tmpPath) === false) {
            return false;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        // Remove previous custom logos
        $current = self::get();
        if (!empty($current['logo_file'])) {
            $old = $dir . '/' . $current['logo_file'];
            if (is_file($old)) {
                @unlink($old);
            }
        }
        foreach (glob($dir . '/logo.*') ?: [] as $f) {
            @unlink($f);
        }

        $ext = $allowed[$mime];
        $filename = 'logo.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) {
            if (!@copy($tmpPath, $dest)) {
                return false;
            }
            @unlink($tmpPath);
        }
        @chmod($dest, 0644);

        return self::writeSettings([
            'site_title' => self::get()['site_title'],
            'logo_file' => $filename,
        ]);
    }

    public static function clearLogo(): bool
    {
        $dir = self::dir();
        $current = self::get();
        if (!empty($current['logo_file'])) {
            $old = $dir . '/' . $current['logo_file'];
            if (is_file($old)) {
                @unlink($old);
            }
        }
        foreach (glob($dir . '/logo.*') ?: [] as $f) {
            @unlink($f);
        }
        return self::writeSettings([
            'site_title' => self::get()['site_title'],
            'logo_file' => null,
        ]);
    }

    /**
     * @param array{site_title: string, logo_file: string|null} $data
     */
    private static function writeSettings(array $data): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $path = $dir . '/' . self::SETTINGS_FILE;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        self::$cache = null;
        return true;
    }
}
