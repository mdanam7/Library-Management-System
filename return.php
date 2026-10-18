<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: borrowings.php'); exit; }

$borrowingId = (int) ($_POST['borrowing_id'] ?? 0);
$msg = '';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT bw.*, c.book_id, c.copy_id, b.title, c.barcode FROM borrowings bw
         JOIN book_copies c ON c.copy_id = bw.copy_id
         JOIN books b       ON b.book_id = c.book_id
         WHERE bw.borrowing_id = ? FOR UPDATE');
    $stmt->execute([$borrowingId]);
    $bw = $stmt->fetch();
    if (!$bw) throw new RuntimeException('Borrowing record not found.');
    if ($bw['return_date'] !== null) throw new RuntimeException('This book was already returned.');

    $days = overdue_days($bw['due_date']);
    $fineCalc = calculate_fine($pdo, $days);
    $fine = $fineCalc['amount'];

    $pdo->prepare('UPDATE borrowings SET return_date = CURDATE() WHERE borrowing_id = ?')->execute([$borrowingId]);

    if ($fine > 0) {
        $reason = "Late return by {$fineCalc['days_late']} day(s) ({$fineCalc['billable_days']} billable after grace period)";
        if ($fineCalc['capped']) {
            $reason .= " [Capped at ceiling]";
        }
        $stmt = $pdo->prepare('INSERT INTO fines (borrowing_id, amount, reason, imposed_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$borrowingId, $fine, $reason, (int) $_SESSION['user_id']]);
    }

    // copy goes back to available, unless someone reserved this book
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE book_id = ? AND status = 'pending'");
    $stmt->execute([(int) $bw['book_id']]);
    $newStatus = ((int) $stmt->fetchColumn() > 0) ? 'reserved' : 'available';
    $pdo->prepare('UPDATE book_copies SET status = ? WHERE copy_id = ?')->execute([$newStatus, (int) $bw['copy_id']]);

    log_activity($pdo, (int) $_SESSION['user_id'], 'RETURN', "borrowing_id=$borrowingId fine=$fine");
    $pdo->commit();

    $curr = e(get_setting($pdo, 'currency', 'BDT'));
    $msg = $fine > 0
        ? "Book '{$bw['title']}' returned. Late by {$fineCalc['days_late']} day(s) — fine of " . number_format($fine, 2) . " $curr auto-generated."
        : "Book '{$bw['title']}' returned on time. No fine.";
    header('Location: borrowings.php?msg=' . urlencode($msg));
} catch (RuntimeException $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: borrowings.php?err=' . urlencode($ex->getMessage()));
}
exit;
?>