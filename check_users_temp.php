<?php
require_once 'config/db.php';
$stmt = $pdo->query('SELECT user_id, full_name, email, role, status, created_at FROM users');
$users = $stmt->fetchAll();
echo "Total Users: " . count($users) . "\n";
print_r($users);
