<?php
/**
 * End admin session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/core/auth.php';

use WikiApp\Core\Auth;
use function WikiApp\Core\url;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method not allowed.';
    exit;
}

Auth::requireCsrf();
Auth::logout();
header('Location: ' . url('admin/login.php'));
exit;
