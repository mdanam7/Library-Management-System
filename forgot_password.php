<?php
require_once 'config/db.php';
require_once 'includes/auth.php';

$message = '';
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND status = "active"');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        // Always show the same message (don't reveal whether the email exists)
        $message = 'If this email is registered, a password reset link has been generated.';

        if ($userId) {
            // invalidate previous tokens
            $pdo->prepare('UPDATE password_resets SET is_used = 1 WHERE user_id = ?')->execute([$userId]);

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([(int) $userId, $tokenHash, $expires]);

            // TODO (production): email the link with mail(). For local dev (no SMTP),
            // the link is displayed on screen below.
            $resetLink = 'reset_password.php?token=' . $token;
        }
    }
}

$page = 'Forgot Password';
include 'includes/header.php';
?>
<div class="card">
  <h2>Reset Password</h2>
  <p class="sub">Enter your account email and we will generate a reset link</p>

  <?php if ($message): ?>
    <div class="msg info"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <?php if ($resetLink): ?>
    <p style="font-size:.85rem;color:#4a5568;margin-top:6px">
      Dev mode (no SMTP configured) — your reset link:</p>
    <div class="reset-link">
      <a href="<?= htmlspecialchars($resetLink) ?>"><?= htmlspecialchars($resetLink) ?></a>
    </div>
    <p style="font-size:.78rem;color:#718096;margin-top:8px">Link expires in 1 hour.</p>
  <?php endif; ?>

  <form id="forgot-form" method="POST" action="forgot_password.php" novalidate>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required>
    <button type="submit">Send Reset Link</button>
  </form>
  <p class="hint"><a href="login.php">Back to login</a></p>
</div>
<?php include 'includes/footer.php'; ?>