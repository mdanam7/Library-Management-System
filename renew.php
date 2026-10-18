<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: borrowings.php'); exit; }

$borrowingId = (int) ($_POST['borrowing_id'] ?? 0);
$uid = (int) $_SESSION['user_id'];
$isStaff = in_array(current_role(), ['admin', 'librarian'], true);

$stmt = $pdo->prepare(
    'SELECT bw.*, u.full_name FROM borrowings bw JOIN users u ON u.user_id = bw.user_id WHERE bw.borrowing_id = ?');
$stmt->execute([$borrowingId]);
$bw = $stmt->fetch();

if (!$bw || (!$isStaff && (int) $bw['user_id'] !== $uid)) {
    header('Location: borrowings.php?err=' . urlencode('Not allowed.')); exit;
}
if ($bw['return_date'] !== null) {
    header('Location: borrowings.php?err=' . urlencode('Already returned.')); exit;
}
if (overdue_days($bw['due_date']) > 0) {
    header('Location: borrowings.php?err=' . urlencode('Overdue books must be returned — renewal is blocked.')); exit;
}

$membership = user_membership($pdo, (int) $bw['user_id']);
if (!$membership) {
    header('Location: borrowings.php?err=' . urlencode('No active membership.')); exit;
}
if ((int) $bw['renewal_count'] >= (int) $membership['renewal_limit']) {
    header('Location: borrowings.php?err=' . urlencode(
        "Renewal limit reached ({$membership['renewal_limit']} for {$membership['type_name']}).")); exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM fines WHERE borrowing_id = ? AND status = 'pending'");
$stmt->execute([$borrowingId]);
if ((int) $stmt->fetchColumn() > 0) {
    header('Location: borrowings.php?err=' . urlencode('Pay the pending fine on this loan before renewing.')); exit;
}

$newDue = date('Y-m-d', strtotime('+' . (int) $membership['loan_days'] . ' days'));
$pdo->prepare('UPDATE borrowings SET due_date = ?, renewal_count = renewal_count + 1 WHERE borrowing_id = ?')
    ->execute([$newDue, $borrowingId]);
log_activity($pdo, $uid, 'RENEW', "borrowing_id=$borrowingId new_due=$newDue");

header('Location: borrowings.php?msg=' . urlencode("Renewed. New due date: $newDue"));
exit;
?>