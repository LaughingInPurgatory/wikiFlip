<?php
/**
 * Admin login form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/core/auth.php';

use WikiApp\Core\Auth;
use function WikiApp\Core\e;
use function WikiApp\Core\url;

Auth::startSession();

// Already signed in → dashboard
if (Auth::check()) {
    header('Location: ' . url('admin/'));
    exit;
}

$error = '';
$returnTo = (string) ($_GET['return'] ?? $_POST['return'] ?? url('admin/'));
// Only allow relative return paths within this app (no open redirects)
if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
    $returnTo = url('admin/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (Auth::attempt($username, $password)) {
        header('Location: ' . $returnTo);
        exit;
    }
    $error = 'Invalid username or password.';
}

$isAdmin = false; // login page uses public chrome (no TinyMCE)
$pageTitle = 'Admin login';
$currentSlug = '';

require __DIR__ . '/../src/includes/header.php';
?>
<section class="admin-panel card login-panel">
    <div class="panel-header">
        <h2>Admin login</h2>
    </div>

    <?php if ($error !== ''): ?>
        <div class="save-status is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/login.php')) ?>" class="login-form" autocomplete="on">
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus
                   value="<?= e((string) ($_POST['username'] ?? '')) ?>"
                   autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Sign in</button>
            <a class="btn btn-ghost" href="<?= e(url()) ?>">← Back to wiki</a>
        </div>
    </form>
</section>
<?php
require __DIR__ . '/../src/includes/footer.php';
