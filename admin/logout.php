<?php
/**
 * End admin session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/core/auth.php';

use WikiApp\Core\Auth;
use function WikiApp\Core\url;

Auth::logout();
header('Location: ' . url('admin/login.php'));
exit;
