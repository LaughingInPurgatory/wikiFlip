<?php
/**
 * Legacy entry point — redirect to the public front controller.
 * Prefer /index.php?slug=... or clean URLs via router.php / .htaccess.
 */

declare(strict_types=1);

$slug = $_GET['slug'] ?? 'home';
$query = http_build_query(['slug' => $slug]);
header('Location: ../../index.php?' . $query, true, 302);
exit;
