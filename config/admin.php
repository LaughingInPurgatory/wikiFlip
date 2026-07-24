<?php
/**
 * Admin credentials for WikiFlip (fallback when env is not set).
 *
 * Preferred in Docker: set WIKIFLIP_ADMIN_USER + WIKIFLIP_ADMIN_PASSWORD
 * (or WIKIFLIP_ADMIN_PASSWORD_HASH) in compose.yaml / .env.
 *
 * To change the hash here:
 *   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
 */

declare(strict_types=1);

return [
    'username' => 'admin',
    // password: password
    'password_hash' => '$2y$12$944obhl7YUR.ZIqyCKnzkebG8esMNlDmFWG78DblzI9L.cu8OxOkW',
];
