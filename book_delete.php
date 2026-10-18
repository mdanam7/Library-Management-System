<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['book_id'] ?? 0);
    // block if any copy has ever been borrowed (FK protection)
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM borrowings bw JOIN book_copies c ON c.copy_id = bw.copy_id WHERE c.book_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        header('Location: books.php?err=' . urlencode('Cannot delete: this book has borrowing history.'));
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM books WHERE book_id = ?');
    $stmt->execute([$id]);
    log_activity($pdo, (int) $_SESSION['user_id'], 'BOOK_DELETE', "book_id=$id");
    header('Location: books.php?msg=Book deleted.');
    exit;
}
header('Location: books.php');
?>