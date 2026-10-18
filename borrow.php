<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_staff();

$errors = []; $done = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $copyId   = (int) ($_POST['copy_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $membership = user_membership($pdo, $memberId);
        if (!$membership) throw new RuntimeException('Member has no active membership.');

        // Check if member is suspended due to unpaid fines
        if (is_borrowing_suspended($pdo, $memberId)) {
            $thresh = get_setting($pdo, 'borrow_suspension_threshold', '100.00');
            $unpaid = unpaid_fine_total($pdo, $memberId);
            throw new RuntimeException("Borrowing blocked: Member has unpaid fines of " . number_format($unpaid, 2) . " BDT (exceeds threshold of $thresh BDT). Fines must be cleared before issuing.");
        }

        if (active_borrow_count($pdo, $memberId) >= (int) $membership['max_books']) {
            throw new RuntimeException("Member has reached their {$membership['max_books']}-book limit ({$membership['type_name']}).");
        }

        $stmt = $pdo->prepare("SELECT * FROM book_copies WHERE copy_id = ? AND status = 'available' FOR UPDATE");
        $stmt->execute([$copyId]);
        $copy = $stmt->fetch();
        if (!$copy) throw new RuntimeException('That copy is not available (already borrowed, reserved, or in maintenance).');

        $due = date('Y-m-d', strtotime('+' . (int) $membership['loan_days'] . ' days'));
        $stmt = $pdo->prepare(
            'INSERT INTO borrowings (user_id, copy_id, issue_date, due_date, issued_by) VALUES (?, ?, CURDATE(), ?, ?)');
        $stmt->execute([$memberId, $copyId, $due, (int) $_SESSION['user_id']]);

        $pdo->prepare("UPDATE book_copies SET status = 'borrowed' WHERE copy_id = ?")->execute([$copyId]);
        log_activity($pdo, (int) $_SESSION['user_id'], 'ISSUE', "copy_id=$copyId to user_id=$memberId due=$due");
        $pdo->commit();
        $done = "✓ Book issued successfully to member! Due date: $due (" . (int) $membership['loan_days'] . " days loan period).";
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $ex->getMessage();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Database error while issuing book.';
    }
}

$members = $pdo->query(
    "SELECT u.user_id, u.full_name, u.email,
        (SELECT mt.type_name FROM memberships m JOIN membership_types mt
            ON mt.membership_type_id = m.membership_type_id
         WHERE m.user_id = u.user_id AND m.status = 'active'
         ORDER BY m.start_date DESC LIMIT 1) AS mtype,
        (SELECT COUNT(*) FROM borrowings bw WHERE bw.user_id = u.user_id AND bw.return_date IS NULL) AS active,
        (SELECT COALESCE(SUM(fn.amount), 0) FROM fines fn JOIN borrowings bw2 ON bw2.borrowing_id = fn.borrowing_id WHERE bw2.user_id = u.user_id AND fn.status = 'pending') AS unpaid_fines
     FROM users u WHERE u.role = 'member' AND u.status = 'active' ORDER BY u.full_name"
)->fetchAll();

$copies = $pdo->query(
    "SELECT c.copy_id, c.barcode, b.title, c.location FROM book_copies c
     JOIN books b ON b.book_id = c.book_id
     WHERE c.status = 'available' ORDER BY b.title, c.barcode"
)->fetchAll();

$suspensionThreshold = (float) get_setting($pdo, 'borrow_suspension_threshold', '100.00');

$page = 'Issue Book';
include 'includes/header.php';
?>
<div class="card wide" style="max-width:680px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
    <div>
      <h2>📖 Issue a Book to Member</h2>
      <p class="sub" style="margin-bottom:0">Circulation desk checkout — select member and available copy</p>
    </div>
    <a href="librarian_dashboard.php" class="btn-sm btn-secondary" style="text-decoration:none;">← Librarian Desk</a>
  </div>

  <?php foreach ($errors as $er): ?><div class="msg error"><?= e($er) ?></div><?php endforeach; ?>
  <?php if ($done): ?><div class="msg success"><?= e($done) ?></div><?php endif; ?>

  <form method="post" action="borrow.php" style="margin-top:16px;">
    <label>Select Borrowing Member *</label>
    <select name="member_id" required>
      <option value="">— Choose Member —</option>
      <?php foreach ($members as $m): 
        $isSuspended = ((float)$m['unpaid_fines'] >= $suspensionThreshold && $suspensionThreshold > 0);
      ?>
        <option value="<?= (int) $m['user_id'] ?>" <?= $isSuspended ? 'style="color:red;"' : '' ?>>
          <?= e($m['full_name']) ?> (<?= e($m['mtype'] ?: 'Regular') ?>) — <?= (int) $m['active'] ?> active loan(s)
          <?= (float)$m['unpaid_fines'] > 0 ? ' • ' . number_format((float)$m['unpaid_fines'], 2) . ' BDT fine' : '' ?>
          <?= $isSuspended ? ' [🚫 SUSPENDED]' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Select Available Physical Copy *</label>
    <select name="copy_id" required>
      <option value="">— Choose Available Copy —</option>
      <?php foreach ($copies as $c): ?>
        <option value="<?= (int) $c['copy_id'] ?>">
          "<?= e($c['title']) ?>" • Barcode: <?= e($c['barcode']) ?> <?= $c['location'] ? '(' . e($c['location']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div style="margin-top:20px;display:flex;gap:12px;align-items:center;">
      <button type="submit" class="btn" style="margin:0;width:auto;padding:11px 26px;">
        ✓ Issue Book Now
      </button>
      <a href="librarian_dashboard.php" class="btn-sm btn-secondary" style="text-decoration:none;">Cancel</a>
    </div>
  </form>
</div>
<?php include 'includes/footer.php'; ?>