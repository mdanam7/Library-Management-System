<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: books.php'); exit; }

$uid = (int) $_SESSION['user_id'];
$bookId = (int) ($_POST['book_id'] ?? 0);

// checks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND book_id = ? AND status = 'pending'");
$stmt->execute([$uid, $bookId]);
if ((int) $stmt->fetchColumn() > 0) {
    header('Location: books.php?err=' . urlencode('You already reserved this book.')); exit;
}

$max = (int) get_setting($pdo, 'max_active_reservations', '3');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'pending'");
$stmt->execute([$uid]);
if ((int) $stmt->fetchColumn() >= $max) {
    header('Location: books.php?err=' . urlencode("Reservation limit reached ($max active).")); exit;
}

$stmt = $pdo->prepare('INSERT INTO reservations (user_id, book_id, expiry_date) VALUES (?,?, DATE_ADD(CURDATE(), INTERVAL 7 DAY))');
$stmt->execute([$uid, $bookId]);
log_activity($pdo, $uid, 'RESERVE', "book_id=$bookId");

header('Location: reservations.php?msg=' . urlencode('Book reserved. You will be served when a copy is returned.'));
exit;
?>