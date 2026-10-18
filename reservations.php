<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$uid = (int) $_SESSION['user_id'];
$role = current_role();
$isStaff = in_array($role, ['admin', 'librarian'], true);
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $resId  = (int) ($_POST['reservation_id'] ?? 0);

    if ($action === 'cancel') {
        $sql = "UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ? AND status = 'pending'";
        $params = [$resId];
        if (!$isStaff) { $sql .= ' AND user_id = ?'; $params[] = $uid; }
        $pdo->prepare($sql)->execute($params);
        $msg = 'Reservation cancelled.';
    } elseif ($action === 'fulfill' && $isStaff) {
        $copyId = (int) ($_POST['copy_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $rStmt = $pdo->prepare("SELECT * FROM reservations WHERE reservation_id = ? AND status = 'pending' FOR UPDATE");
            $rStmt->execute([$resId]);
            $res = $rStmt->fetch();

            if (!$res) throw new RuntimeException("Reservation not found or already fulfilled.");

            $memberId = (int) $res['user_id'];
            $mem = user_membership($pdo, $memberId);
            if (!$mem) throw new RuntimeException("Member has no active membership.");

            if (is_borrowing_suspended($pdo, $memberId)) {
                throw new RuntimeException("Borrowing blocked: Member has unpaid overdue fines exceeding threshold.");
            }

            if (active_borrow_count($pdo, $memberId) >= (int) $mem['max_books']) {
                throw new RuntimeException("Member reached maximum active book limit.");
            }

            $copyStmt = $pdo->prepare("SELECT * FROM book_copies WHERE copy_id = ? AND status IN ('available', 'reserved') FOR UPDATE");
            $copyStmt->execute([$copyId]);
            $copy = $copyStmt->fetch();

            if (!$copy) throw new RuntimeException("Copy is not ready for issue.");

            $loanDays = (int) $mem['loan_days'];
            $dueDate = date('Y-m-d', strtotime("+$loanDays days"));

            $pdo->prepare("INSERT INTO borrowings (user_id, copy_id, issue_date, due_date, issued_by) VALUES (?, ?, CURDATE(), ?, ?)")
                ->execute([$memberId, $copyId, $dueDate, $uid]);

            $pdo->prepare("UPDATE reservations SET status = 'fulfilled' WHERE reservation_id = ?")->execute([$resId]);
            $pdo->prepare("UPDATE book_copies SET status = 'borrowed' WHERE copy_id = ?")->execute([$copyId]);

            log_activity($pdo, $uid, 'FULFILL_RESERVATION', "res_id=$resId copy_id=$copyId");
            $pdo->commit();
            $msg = "✓ Reservation fulfilled and book issued. Due date: $dueDate.";
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $ex->getMessage();
        }
    }
}

if ($isStaff) {
    $rows = $pdo->query(
        "SELECT r.*, b.title, u.full_name, u.email,
            (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = r.book_id AND bc.status IN ('available', 'reserved')) AS available,
            (SELECT bc2.copy_id FROM book_copies bc2 WHERE bc2.book_id = r.book_id AND bc2.status IN ('available', 'reserved') LIMIT 1) AS avail_copy_id,
            (SELECT bc3.barcode FROM book_copies bc3 WHERE bc3.book_id = r.book_id AND bc3.status IN ('available', 'reserved') LIMIT 1) AS avail_barcode
         FROM reservations r
         JOIN books b ON b.book_id = r.book_id
         JOIN users u ON u.user_id = r.user_id
         ORDER BY r.status = 'pending' DESC, r.reservation_date DESC LIMIT 200"
    )->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT r.*, b.title, NULL AS full_name, NULL AS email,
            (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = r.book_id AND bc.status = 'available') AS available,
            NULL AS avail_copy_id, NULL AS avail_barcode
         FROM reservations r JOIN books b ON b.book_id = r.book_id
         WHERE r.user_id = ? ORDER BY r.status = 'pending' DESC, r.reservation_date DESC");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();
}

$page = 'Reservations';
include 'includes/header.php';
?>
<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
    <div>
      <h2>📌 <?= $isStaff ? 'All Member Book Reservations' : 'My Book Reservations' ?></h2>
      <p class="sub" style="margin-bottom:0">Reserved books are held for members as soon as copies are checked in.</p>
    </div>
    <?php if (!$isStaff): ?>
      <a href="books.php" class="btn-sm" style="text-decoration:none;">🔍 Reserve from Catalog</a>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Book Title</th>
        <?php if ($isStaff): ?><th>Member</th><?php endif; ?>
        <th>Reserved On</th>
        <th>Hold Expiry</th>
        <th>Availability</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $st = $r['status'];
        $stTag = match($st) {
            'pending'   => 'warn',
            'fulfilled' => 'ok',
            'cancelled' => 'bad',
            default     => 'info'
        };
      ?>
        <tr>
          <td><strong><?= e($r['title']) ?></strong></td>
          <?php if ($isStaff): ?>
            <td>
              <strong><?= e($r['full_name']) ?></strong><br>
              <small style="color:var(--muted);"><?= e($r['email']) ?></small>
            </td>
          <?php endif; ?>
          <td><?= date('M d, Y', strtotime($r['reservation_date'])) ?></td>
          <td><?= $r['expiry_date'] ? date('M d, Y', strtotime($r['expiry_date'])) : '–' ?></td>
          <td>
            <?php if ((int)$r['available'] > 0): ?>
              <span class="tag ok"><?= (int)$r['available'] ?> copy(ies) ready</span>
            <?php else: ?>
              <span class="tag warn">Waiting for return</span>
            <?php endif; ?>
          </td>
          <td><span class="tag <?= $stTag ?>"><?= ucfirst(e($st)) ?></span></td>
          <td class="actions">
            <?php if ($st === 'pending'): ?>
              <?php if ($isStaff && !empty($r['avail_copy_id'])): ?>
                <form method="post" action="reservations.php" style="display:inline;">
                  <input type="hidden" name="action" value="fulfill">
                  <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
                  <input type="hidden" name="copy_id" value="<?= (int) $r['avail_copy_id'] ?>">
                  <button class="btn-sm btn-success" type="submit" onclick="return confirm('Fulfill reservation and issue copy barcode <?= e($r['avail_barcode']) ?>?')">
                    Issue Copy
                  </button>
                </form>
              <?php endif; ?>

              <form method="post" action="reservations.php" onsubmit="return confirm('Cancel this reservation?')" style="display:inline;">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="reservation_id" value="<?= (int) $r['reservation_id'] ?>">
                <button class="btn-sm btn-danger" type="submit">Cancel</button>
              </form>
            <?php else: ?>
              –
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $isStaff ? 7 : 6 ?>" style="text-align:center;padding:24px;color:var(--muted);">No reservations found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if (!$isStaff): ?>
    <p class="hint"><a href="books.php">→ Browse the book catalog to reserve your next read</a></p>
  <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>