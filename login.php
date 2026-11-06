<?php
require 'config/database.php';
require 'config/auth.php';
require 'config/settings.php';

if (isLoggedIn()) {
    header('Location: dashboard');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Security addition: Inirere-generate ang session ID pagka-login
            session_regenerate_id(true);

            $sessionToken = bin2hex(random_bytes(32));

            $stmt = $pdo->prepare("UPDATE users SET current_session_token = ? WHERE id = ?");
            $stmt->execute([$sessionToken, $user['id']]);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['session_token'] = $sessionToken;
            $_SESSION['last_activity'] = time();
            header('Location: dashboard');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

if (isset($_GET['timeout'])) {
    $error = 'You were signed out after 10 minutes of inactivity. Please log in again.';
} elseif (isset($_GET['kicked'])) {
    $error = 'This account was signed in from another browser or device, so you were signed out here.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - <?= htmlspecialchars(BUSINESS_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(DEV_COMPANY_LOGO) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body d-flex align-items-center justify-content-center">
    <div class="card login-card shadow-lg">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <img src="<?= htmlspecialchars(BUSINESS_LOGO) ?>" alt="<?= htmlspecialchars(BUSINESS_NAME) ?>" class="login-logo mb-2">
                <p class="text-muted small mb-0"><?= htmlspecialchars(BUSINESS_TAGLINE) ?></p>
                <p class="text-muted small mt-2">Sign in to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>

            <div class="dev-credit">
                <img src="<?= htmlspecialchars(DEV_COMPANY_LOGO) ?>" alt="<?= htmlspecialchars(DEV_COMPANY_NAME) ?>">
                <span>Powered by <?= htmlspecialchars(DEV_COMPANY_NAME) ?></span>
            </div>
        </div>
    </div>
    <script src="assets/js/devtools-block.js"></script>
</body>
</html>