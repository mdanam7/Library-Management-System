<?php
require_once 'config/db.php';
require_once 'includes/auth.php';

$errors = [];
$success = false;
$old = ['full_name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['email']     = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm          = $_POST['confirm_password'] ?? '';

    if ($old['full_name'] === '' || strlen($old['full_name']) < 3) {
        $errors[] = 'Full name must be at least 3 characters.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        // email must be unique
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$old['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, password_hash, role)
                 VALUES (?, ?, ?, 'member')"
            );
            $stmt->execute([$old['full_name'], $old['email'], password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();

            // assign default Regular membership
            $typeId = (int) $pdo->query(
                "SELECT membership_type_id FROM membership_types WHERE type_name = 'Regular'"
            )->fetchColumn();
            $stmt = $pdo->prepare(
                'INSERT INTO memberships (user_id, membership_type_id, start_date, status)
                 VALUES (?, ?, CURDATE(), "active")'
            );
            $stmt->execute([$userId, $typeId]);

            $pdo->commit();
            log_activity($pdo, $userId, 'REGISTER', 'New member registered');
            $success = true;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}

$page = 'Register';
include 'includes/header.php';
?>
<div class="card">
  <h2>Create Account</h2>
  <p class="sub">Register as a library member</p>

  <?php foreach ($errors as $e): ?>
    <div class="msg error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <?php if ($success): ?>
    <div class="msg success">Registration successful! You can now log in.</div>
    <a class="btn" href="login.php">Go to Login</a>
  <?php else: ?>
  <form id="register-form" method="POST" action="register.php" novalidate>
    <label for="full_name">Full Name</label>
    <input type="text" id="full_name" name="full_name" required
           value="<?= htmlspecialchars($old['full_name']) ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required
           value="<?= htmlspecialchars($old['email']) ?>">

    <label for="password">Password (min 8 characters)</label>
    <input type="password" id="password" name="password" required>

    <label for="confirm_password">Confirm Password</label>
    <input type="password" id="confirm_password" name="confirm_password" required>

    <button type="submit">Register</button>
  </form>
  <p class="hint">Already have an account? <a href="login.php">Login here</a></p>
  <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>