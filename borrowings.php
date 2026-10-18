<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$uid = (int) $_SESSION['user_id'];
$role = current_role();
$isStaff = in_array($role, ['admin', 'librarian'], true);
$f = $_GET['f'] ?? 'active';

$sql = 'SELECT bw.*, u.full_name, u.email, b.title, c.barcode,
        (SELECT mt.renewal_limit FROM memberships ms JOIN membership_types mt
            ON mt.membership_type_id = ms.membership_type_id
         WHERE ms.user_id = bw.user_id AND ms.status = "active"
         ORDER BY ms.start_date DESC LIMIT 1) AS renewal_limit,
        (SELECT COUNT(*) FROM fines fn WHERE fn.borrowing_id = bw.borrowing_id AND fn.status = "pending") AS pending_fines,
        (SELECT fn.fine_id FROM fines fn WHERE fn.borrowing_id = bw.borrowing_id AND fn.status = "pending" LIMIT 1) AS pending_fine_id
        FROM borrowings bw
        JOIN users u       ON u.user_id  = bw.user_id
        JOIN book_copies c ON c.copy_id  = bw.copy_id
        JOIN books b       ON b.book_id  = c.book_id';
$cond = []; $params = [];
if (!$isStaff) { $cond[] = 'bw.user_id = ?'; $params[] = $uid; }
if ($f === 'active')      { $cond[] = 'bw.return_date IS NULL'; }
elseif ($f === 'overdue') { $cond[] = 'bw.return_date IS NULL AND bw.due_date < CURDATE()'; }
elseif ($f === 'returned'){ $cond[] = 'bw.return_date IS NOT NULL'; }
if ($cond) $sql .= ' WHERE ' . implode(' AND ', $cond);
$sql .= ' ORDER BY bw.return_date IS NOT NULL, bw.due_date ASC LIMIT 200';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

$page = 'Borrowings';
include 'includes/header.php';
?>
<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
    <div>
      <h2>📖 <?= $isStaff ? 'All Circulation Borrowings' : 'My Borrowing History' ?></h2>
      <p class="sub" style="margin-bottom:0"><?= count($rows) ?> loan record(s) listed</p>
    </div>
    <?php if ($isStaff): ?>
      <div>
        <a class="btn-sm" style="text-decoration:none;" href="borrow.php">➕ Issue New Book</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="toolbar">
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
      <?php foreach (['active' => 'Active Loans', 'overdue' => 'Overdue Loans', 'returned' => 'Returned History', 'all' => 'All Records'] as $k => $v): ?>
        <a class="btn-sm <?= $f === $k ? '' : 'btn-secondary' ?>" style="text-decoration:none;"
           href="borrowings.php?f=<?= $k ?>"><?= $v ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (isset($_GET['msg'])): ?><div class="msg success"><?= e($_GET['msg']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="msg error"><?= e($_GET['err']) ?></div><?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Loan #</th>
        <?php if ($isStaff): ?><th>Member Details</th><?php endif; ?>
        <th>Book Title</th>
        <th>Barcode</th>
        <th>Issue Date</th>
        <th>Due Date</th>
        <th>Status</th>
        <th>Renewals</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
          $active = $r['return_date'] === null;
          $od = $active ? overdue_days($r['due_date']) : 0;
          $canRenew = $active && $od === 0 && (int) $r['pending_fines'] === 0
                      && (int) $r['renewal_count'] < (int) ($r['renewal_limit'] ?? 1);
      ?>
        <tr>
          <td><code>#BW-<?= (int) $r['borrowing_id'] ?></code></td>
          <?php if ($isStaff): ?>
            <td>
              <strong><?= e($r['full_name']) ?></strong><br>
              <small style="color:var(--muted);"><?= e($r['email']) ?></small>
            </td>
          <?php endif; ?>
          <td><strong><?= e($r['title']) ?></strong></td>
          <td><code><?= e($r['barcode']) ?></code></td>
          <td><?= e($r['issue_date']) ?></td>
          <td class="<?= $od > 0 ? 'overdue' : '' ?>">
            <?= e($r['due_date']) ?>
            <?= $od > 0 ? " (+$od d)" : '' ?>
          </td>
          <td>
            <?php if (!$active): ?>
              <span class="tag ok">Returned <?= e($r['return_date']) ?></span>
            <?php elseif ($od > 0): ?>
              <span class="tag bad">Overdue</span>
            <?php else: ?>
              <span class="tag info">Active Loan</span>
            <?php endif; ?>
            <?php if ((int) $r['pending_fines'] > 0): ?>
              <br><span class="tag warn" style="margin-top:3px;">Fine Pending</span>
            <?php endif; ?>
          </td>
          <td><?= (int) $r['renewal_count'] ?> / <?= (int) ($r['renewal_limit'] ?? 1) ?></td>
          <td class="actions">
            <?php if ($active && $isStaff): ?>
              <form method="post" action="return.php" onsubmit="return confirm('Process return for barcode <?= e($r['barcode']) ?>?')">
                <input type="hidden" name="borrowing_id" value="<?= (int) $r['borrowing_id'] ?>">
                <button class="btn-sm btn-success" type="submit">Return</button>
              </form>
            <?php endif; ?>

            <?php if ($canRenew && ($isStaff || (int) $r['user_id'] === $uid)): ?>
              <form method="post" action="renew.php" style="display:inline;">
                <input type="hidden" name="borrowing_id" value="<?= (int) $r['borrowing_id'] ?>">
                <button class="btn-sm btn-secondary" type="submit">Renew</button>
              </form>
            <?php endif; ?>

            <?php if ((int)$r['pending_fines'] > 0): ?>
              <?php if (!$isStaff && !empty($r['pending_fine_id'])): ?>
                <a href="payment.php?fine_id=<?= (int)$r['pending_fine_id'] ?>" class="btn-sm btn-success" style="text-decoration:none;">
                  Pay Fine
                </a>
              <?php else: ?>
                <a href="fines.php" class="btn-sm btn-secondary" style="text-decoration:none;">View Fine</a>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $isStaff ? 9 : 8 ?>" style="text-align:center;padding:24px;color:var(--muted);">No borrowing records found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php include 'includes/footer.php'; ?>