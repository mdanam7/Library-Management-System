<?php
// One-time admin setup script.
// Run once (http://localhost/your-project/setup_admin.php), then DELETE this file.
require_once 'config/db.php';

$email = 'admin@gmail.com';
$pass  = 'Admin@123';

$stmt = $pdo->prepare(
    "INSERT INTO users (full_name, email, password_hash, role)
     VALUES ('System Admin', ?, ?, 'admin')
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin', status = 'active'"
);
$stmt->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);

echo "<h3>Admin account ready</h3><p>Email: <b>$email</b><br>Password: <b>$pass</b></p>";
?>