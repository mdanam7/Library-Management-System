<?php
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (is_logged_in()) {
    // Redirect already-logged-in users to their respective portal
    $r = current_role();
    if ($r === 'librarian') header('Location: librarian_dashboard.php');
    elseif ($r === 'admin')     header('Location: admin_dashboard.php');
    else                        header('Location: dashboard.php');
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $errors[] = 'Please enter a valid email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } elseif ($user['status'] !== 'active') {
            $errors[] = 'Your account is suspended. Contact the library.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            log_activity($pdo, (int) $user['user_id'], 'LOGIN', 'User logged in');

            // Role-based redirect after login
            if ($user['role'] === 'librarian') {
                header('Location: librarian_dashboard.php');
            } elseif ($user['role'] === 'admin') {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: dashboard.php');
            }
            exit;
        }
    }
}

$page = 'Login';
include 'includes/header.php';
?>
<div class="card">
  <h2>Login</h2>
  <p class="sub">Welcome back to the library</p>

  <?php foreach ($errors as $e): ?>
    <div class="msg error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <form id="login-form" method="POST" action="login.php" novalidate>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required value="<?= htmlspecialchars($email) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Login</button>
  </form>
  <p class="hint"><a href="forgot_password.php">Forgot your password?</a></p>
  <p class="hint">New member? <a href="register.php">Create an account</a></p>

  <!-- Credential Quick-Access Info (dev/demo reference) -->
  <details style="margin-top:20px;border:1px solid var(--border);border-radius:10px;overflow:hidden;">
    <summary style="padding:10px 14px;cursor:pointer;font-size:.84rem;color:var(--muted);background:var(--surface);user-select:none;">🔑 Demo Credentials (for testing)</summary>
    <div style="padding:14px;font-size:.82rem;background:var(--surface);">
      <div style="margin-bottom:8px;padding:8px 12px;border-radius:8px;border:1px solid var(--border);">
        <strong style="color:var(--accent);">📖 Librarian Desk</strong><br>
        Email: <code>librarian@uiu.ac.bd</code> &nbsp;|&nbsp; Password: <code>Librarian@2024</code>
      </div>
      <div style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);">
        <strong style="color:#d97706;">⚙️ Admin Panel</strong><br>
        Email: <code>admin@uiu.ac.bd</code> &nbsp;|&nbsp; Password: <em>set via setup_admin.php</em>
      </div>
    </div>
  </details>
</div>
<?php include 'includes/footer.php'; ?>