<?php

declare(strict_types=1);

namespace WikiApp\Core;

/**
 * Session-based admin authentication.
 *
 * Credentials (first match wins):
 *  1. Environment variables:
 *       WIKIFLIP_ADMIN_USER
 *       WIKIFLIP_ADMIN_PASSWORD          (plaintext — hashed once in memory)
 *       WIKIFLIP_ADMIN_PASSWORD_HASH     (optional bcrypt hash instead of plaintext)
 *  2. Fallback: config/admin.php
 */
final class Auth
{
    private const SESSION_USER_KEY = 'wikiflip_admin_user';
    private const SESSION_CSRF_KEY = 'wikiflip_csrf_token';

    /** @var array{username: string, password_hash: string}|null */
    private static ?array $config = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('wikiflip_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * @return array{username: string, password_hash: string}
     */
    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $envUser = self::env('WIKIFLIP_ADMIN_USER');
        $envPass = self::env('WIKIFLIP_ADMIN_PASSWORD');
        $envHash = self::env('WIKIFLIP_ADMIN_PASSWORD_HASH');

        if ($envUser !== null && $envUser !== '' && ($envPass !== null && $envPass !== '' || $envHash !== null && $envHash !== '')) {
            if ($envHash !== null && $envHash !== '') {
                $hash = $envHash;
            } else {
                // Hash once per process from plaintext env password
                $hash = password_hash((string) $envPass, PASSWORD_DEFAULT);
            }

            self::$config = [
                'username' => $envUser,
                'password_hash' => $hash,
            ];
            return self::$config;
        }

        $path = WIKIFLIP_ROOT . '/config/admin.php';
        if (!is_file($path)) {
            throw new \RuntimeException(
                'Admin credentials not configured. Set WIKIFLIP_ADMIN_USER + WIKIFLIP_ADMIN_PASSWORD '
                . '(or WIKIFLIP_ADMIN_PASSWORD_HASH), or provide config/admin.php.'
            );
        }

        /** @var mixed $cfg */
        $cfg = require $path;
        if (!is_array($cfg) || empty($cfg['username']) || empty($cfg['password_hash'])) {
            throw new \RuntimeException('Invalid admin config in config/admin.php.');
        }

        self::$config = [
            'username' => (string) $cfg['username'],
            'password_hash' => (string) $cfg['password_hash'],
        ];
        return self::$config;
    }

    public static function check(): bool
    {
        self::startSession();
        $user = $_SESSION[self::SESSION_USER_KEY] ?? null;
        return is_string($user) && $user !== '';
    }

    public static function user(): ?string
    {
        self::startSession();
        $user = $_SESSION[self::SESSION_USER_KEY] ?? null;
        return is_string($user) && $user !== '' ? $user : null;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        $token = $_SESSION[self::SESSION_CSRF_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_CSRF_KEY] = $token;
        }
        return $token;
    }

    public static function verifyCsrf(?string $token = null): bool
    {
        self::startSession();
        $expected = $_SESSION[self::SESSION_CSRF_KEY] ?? null;
        $token ??= (string) ($_POST['csrf_token'] ?? '');
        return is_string($expected) && $expected !== ''
            && $token !== ''
            && hash_equals($expected, $token);
    }

    public static function requireCsrf(bool $json = false): void
    {
        if (self::verifyCsrf()) {
            return;
        }

        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid form token.',
                'message' => 'Your form expired. Reload the page and try again.',
            ]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid form token. Reload the page and try again.';
        }
        exit;
    }

    public static function attempt(string $username, string $password): bool
    {
        self::startSession();
        $cfg = self::config();

        $userOk = hash_equals(strtolower($cfg['username']), strtolower(trim($username)));
        $passOk = password_verify($password, $cfg['password_hash']);

        // Also accept plaintext env password when hash was derived this process
        // (password_verify already covers that). Optional: direct env re-check for
        // workers that re-hash — not needed when config() caches the hash.

        if (!$userOk || !$passOk) {
            // If env plaintext is set, allow verify against it without relying solely
            // on the one-time hash (handles rare PASSWORD_DEFAULT algorithm changes).
            $envPass = self::env('WIKIFLIP_ADMIN_PASSWORD');
            if ($userOk && $envPass !== null && $envPass !== '' && hash_equals($envPass, $password)) {
                $passOk = true;
            }
        }

        if (!$userOk || !$passOk) {
            usleep(200000);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_KEY] = $cfg['username'];
        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    /**
     * Require a logged-in admin. HTML pages redirect to login; API returns 401 JSON.
     */
    public static function requireLogin(bool $json = false): void
    {
        if (self::check()) {
            return;
        }

        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required.',
                'message' => 'Authentication required. Please log in.',
            ]);
            exit;
        }

        $return = $_SERVER['REQUEST_URI'] ?? url('admin/');
        $login = url('admin/login.php') . '?return=' . rawurlencode($return);
        header('Location: ' . $login);
        exit;
    }

    private static function env(string $key): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return null;
        }
        return (string) $v;
    }
}
