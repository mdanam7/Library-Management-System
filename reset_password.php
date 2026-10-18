<?php
require_once 'config/db.php';
require_once 'includes/auth.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$errors = [];
$done = false;
$validToken = false;
$userId = null;

if ($token !== '') {
    $stmt = $pdo->prepare(
        'SELECT pr.*, u.user_id AS uid FROM password_resets pr
         JOIN users u ON u.user_id = pr.user_id
         WHERE pr.token_hash = ? AND pr.is_used = 0 AND pr.expires_at > NOW()'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if ($row) {
        $validToken = true;
        $userId = (int) $row['uid'];
    } else {
        $errors[] = 'This reset link is invalid, used, or expired. Please request a new one.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        $pdo->prepare('UPDATE password_resets SET is_used = 1 WHERE user_id = ?')->execute([$userId]);
        $pdo->commit();
        log_activity($pdo, $userId, 'PASSWORD_RESET', 'Password reset via token');
        $done = true;
    }
}

$page = 'Reset Password';
include 'includes/header.php';
?>
<div class="card">
  <h2>Set a New Password</h2>
  <p class="sub">Choose a strong password for your account</p>

  <?php foreach ($errors as $e): ?>
    <div class="msg error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <?php if ($done): ?>
    <div class="msg success">Password updated! You can now log in with your new password.</div>
    <a class="btn" href="login.php">Go to Login</a>
  <?php elseif ($validToken): ?>
  <form id="reset-form" method="POST" action="reset_password.php" novalidate>
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <label for="password">New Password (min 8 characters)</label>
    <input type="password" id="password" name="password" required>
    <label for="confirm_password">Confirm New Password</label>
    <input type="password" id="confirm_password" name="confirm_password" required>
    <button type="submit">Update Password</button>
  </form>
  <?php else: ?>
    <p class="hint"><a href="forgot_password.php">Request a new reset link</a> · <a href="login.php">Back to login</a></p>
  <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>