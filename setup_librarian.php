<?php
/**
 * Setup Librarian Account + Run Migrations
 * -----------------------------------------
 * Creates a dedicated librarian account with specific credentials
 * and ensures all necessary DB schema migrations are applied.
 *
 * Run this script ONCE via browser:
 *   http://localhost/Project/final_dbms_lab/lms/setup_librarian.php
 *
 * Librarian Login Credentials:
 *   Email:    librarian@uiu.ac.bd
 *   Password: Librarian@2024
 */
require_once 'config/db.php';
require_once 'includes/auth.php';

$libEmail    = 'librarian@uiu.ac.bd';
$libPassword = 'Librarian@2024';
$libName     = 'UIU Chief Librarian';

$logs = [];
$success = true;

// ---------------------------------------------------------------
// STEP 1: Run Schema Migrations (idempotent — safe to run again)
// ---------------------------------------------------------------

// 1a. membership_requests table
try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS membership_requests (
      request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      membership_type_id INT UNSIGNED NOT NULL,
      duration_months INT UNSIGNED NOT NULL DEFAULT 1,
      fee DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
      payment_method VARCHAR(50) NOT NULL DEFAULT 'demo-card',
      transaction_ref VARCHAR(100) NULL,
      payment_status ENUM('paid', 'pending', 'waived') NOT NULL DEFAULT 'paid',
      notes TEXT NULL,
      status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
      admin_notes TEXT NULL,
      reviewed_by INT UNSIGNED NULL,
      reviewed_at TIMESTAMP NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
      FOREIGN KEY (membership_type_id) REFERENCES membership_types(membership_type_id),
      FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
      INDEX idx_mreq_user (user_id),
      INDEX idx_mreq_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $logs[] = ['ok', '✓ membership_requests table verified/created.'];
} catch (Exception $e) {
    $logs[] = ['warn', '⚠ membership_requests: ' . $e->getMessage()];
}

// 1b. Fine & penalty settings
$settings = [
    'fine_per_day'              => ['5.00',   'Fine amount per day for late book return'],
    'fine_grace_period_days'    => ['2',      'Grace period days before fines start accumulating'],
    'max_fine_per_book'         => ['500.00', 'Maximum fine ceiling per book'],
    'lost_book_fee'             => ['400.00', 'Standard replacement fee for lost book'],
    'damaged_book_fee'          => ['150.00', 'Standard penalty for damaged book'],
    'borrow_suspension_threshold' => ['100.00', 'Fine amount threshold where borrowing privileges are suspended'],
    'currency'                  => ['BDT',    'Currency used for fines and fees'],
    'max_active_reservations'   => ['3',      'Maximum active reservations per member'],
];
try {
    $insStmt = $pdo->prepare("INSERT INTO library_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)");
    foreach ($settings as $k => $v) {
        $insStmt->execute([$k, $v[0], $v[1]]);
    }
    $logs[] = ['ok', '✓ Fine and penalty settings inserted/verified.'];
} catch (Exception $e) {
    $logs[] = ['warn', '⚠ settings: ' . $e->getMessage()];
}

// 1c. Extend payments table
try {
    $cols = $pdo->query("SHOW COLUMNS FROM payments LIKE 'transaction_ref'")->fetchAll();
    if (empty($cols)) {
        try { $pdo->exec("ALTER TABLE payments DROP FOREIGN KEY payments_ibfk_1"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE payments DROP INDEX fine_id"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE payments MODIFY fine_id INT UNSIGNED NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE payments ADD CONSTRAINT fk_payments_fine FOREIGN KEY (fine_id) REFERENCES fines(fine_id) ON DELETE SET NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE payments ADD COLUMN transaction_ref VARCHAR(100) NULL AFTER payment_method"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE payments ADD COLUMN payment_type VARCHAR(50) NOT NULL DEFAULT 'fine' AFTER transaction_ref"); } catch (Exception $e) {}
        $logs[] = ['ok', '✓ payments table schema extended.'];
    } else {
        $logs[] = ['ok', '✓ payments table already up-to-date.'];
    }
} catch (Exception $e) {
    $logs[] = ['warn', '⚠ payments: ' . $e->getMessage()];
}

// 1d. Extend fines table (add waiver_reason + imposed_by)
try {
    $fCols = $pdo->query("SHOW COLUMNS FROM fines LIKE 'waiver_reason'")->fetchAll();
    if (empty($fCols)) {
        try { $pdo->exec("ALTER TABLE fines ADD COLUMN waiver_reason VARCHAR(255) NULL AFTER paid_date"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE fines ADD COLUMN imposed_by INT UNSIGNED NULL AFTER waiver_reason"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE fines ADD CONSTRAINT fk_fines_imposed_by FOREIGN KEY (imposed_by) REFERENCES users(user_id) ON DELETE SET NULL"); } catch (Exception $e) {}
        $logs[] = ['ok', '✓ fines table schema extended (waiver_reason, imposed_by).'];
    } else {
        $logs[] = ['ok', '✓ fines table already up-to-date.'];
    }
} catch (Exception $e) {
    $logs[] = ['warn', '⚠ fines: ' . $e->getMessage()];
}

// ---------------------------------------------------------------
// STEP 2: Create / Update Librarian Account
// ---------------------------------------------------------------
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT user_id, role FROM users WHERE email = ?');
    $stmt->execute([$libEmail]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare("UPDATE users SET full_name = ?, role = 'librarian', status = 'active', password_hash = ? WHERE email = ?")
            ->execute([$libName, password_hash($libPassword, PASSWORD_DEFAULT), $libEmail]);
        $logs[] = ['ok', "✓ Librarian account updated (role enforced to 'librarian', status='active')."];
    } else {
        $pwdHash = password_hash($libPassword, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'librarian', 'active')")
            ->execute([$libName, $libEmail, $pwdHash]);
        $logs[] = ['ok', '✓ Librarian account created successfully!'];
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $logs[] = ['error', '✗ Librarian account error: ' . $e->getMessage()];
    $success = false;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LMS — Librarian Setup</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .setup-box { background: #1e293b; border: 1px solid #334155; border-radius: 20px; padding: 40px; max-width: 560px; width: 100%; box-shadow: 0 25px 60px rgba(0,0,0,.6); }
  h1 { color: #f8fafc; font-size: 1.6rem; margin-bottom: 6px; }
  .subtitle { color: #94a3b8; font-size: .9rem; margin-bottom: 28px; }
  .log-list { list-style: none; margin-bottom: 28px; }
  .log-list li { display: flex; align-items: flex-start; gap: 10px; padding: 9px 14px; border-radius: 8px; margin-bottom: 6px; font-size: .875rem; }
  .log-ok   { background: #022c22; color: #4ade80; border: 1px solid #166534; }
  .log-warn { background: #422006; color: #fb923c; border: 1px solid #9a3412; }
  .log-error{ background: #450a0a; color: #f87171; border: 1px solid #991b1b; }
  .cred-box { background: #0f172a; border: 1.5px solid #38bdf8; border-radius: 14px; padding: 24px; margin-bottom: 24px; }
  .cred-box h3 { color: #38bdf8; font-size: 1rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
  .cred-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #1e293b; }
  .cred-row:last-child { border-bottom: none; }
  .cred-label { color: #94a3b8; font-size: .85rem; font-weight: 600; }
  .cred-value { background: #1e293b; color: #f0f9ff; padding: 5px 14px; border-radius: 8px; font-family: 'JetBrains Mono', monospace, sans-serif; font-size: .9rem; letter-spacing: .5px; border: 1px solid #334155; }
  .note { color: #64748b; font-size: .8rem; margin-bottom: 20px; line-height: 1.6; }
  .btn-login { display: block; text-align: center; background: linear-gradient(135deg, #0ea5e9, #6366f1); color: white; text-decoration: none; padding: 14px 28px; border-radius: 12px; font-weight: 700; font-size: 1rem; letter-spacing: .3px; transition: opacity .2s; }
  .btn-login:hover { opacity: .88; }
  .icon { font-size: 1.4rem; }
</style>
</head>
<body>
<div class="setup-box">
  <h1>📖 LMS — Librarian Setup</h1>
  <p class="subtitle">Schema migrations + librarian account provisioning</p>

  <ul class="log-list">
    <?php foreach ($logs as [$type, $msg]): ?>
      <li class="log-<?= $type ?>">
        <span class="icon"><?= $type === 'ok' ? '✓' : ($type === 'warn' ? '⚠' : '✗') ?></span>
        <?= htmlspecialchars($msg) ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($success): ?>
  <div class="cred-box">
    <h3>🔑 Librarian Login Credentials</h3>
    <div class="cred-row">
      <span class="cred-label">Email</span>
      <span class="cred-value"><?= htmlspecialchars($libEmail) ?></span>
    </div>
    <div class="cred-row">
      <span class="cred-label">Password</span>
      <span class="cred-value"><?= htmlspecialchars($libPassword) ?></span>
    </div>
    <div class="cred-row">
      <span class="cred-label">Role</span>
      <span class="cred-value">librarian</span>
    </div>
  </div>
  <p class="note">
    Use these credentials at <strong>login.php</strong> to access the independent Librarian Desk.<br>
    The librarian portal is completely separate from the Admin Panel — it has its own navigation, dashboard, and controls.
  </p>
  <a href="login.php" class="btn-login">🚀 Go to Login →</a>
  <?php else: ?>
  <p style="color:#f87171;margin-bottom:16px;">Setup encountered errors. Please check the logs above and ensure MySQL is running.</p>
  <a href="setup_librarian.php" class="btn-login" style="background:linear-gradient(135deg,#dc2626,#9f1239);">🔄 Retry Setup</a>
  <?php endif; ?>
</div>
</body>
</html>
