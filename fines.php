<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$uid = (int) $_SESSION['user_id'];
$role = current_role();
$isStaff = in_array($role, ['admin', 'librarian'], true);
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// Handle Staff Actions (Waive with Reason, Settle Desk Cash)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStaff) {
    $action = $_POST['action'] ?? '';
    $fineId = (int) ($_POST['fine_id'] ?? 0);

    if ($action === 'waive') {
        $waiverReason = trim($_POST['waiver_reason'] ?? 'First-Time Courtesy Waiver');
        if (!$waiverReason) $waiverReason = 'Administrative Waiver';

        $stmt = $pdo->prepare("UPDATE fines SET status = 'waived', waiver_reason = ? WHERE fine_id = ? AND status = 'pending'");
        $stmt->execute([$waiverReason, $fineId]);

        log_activity($pdo, $uid, 'FINE_WAIVE', "fine_id=$fineId reason=$waiverReason");
        $msg = "✓ Fine #$fineId successfully waived. Justification recorded: '$waiverReason'.";
    }

    elseif ($action === 'collect_cash') {
        $method = trim($_POST['payment_method'] ?? 'Desk Cash');
        $deskRef = trim($_POST['desk_ref'] ?? ('DESK-CASH-' . date('Ymd') . '-' . rand(100, 999)));

        try {
            $pdo->beginTransaction();

            $fStmt = $pdo->prepare("SELECT f.*, bw.user_id AS borrower_id FROM fines f JOIN borrowings bw ON bw.borrowing_id = f.borrowing_id WHERE f.fine_id = ? AND f.status = 'pending' FOR UPDATE");
            $fStmt->execute([$fineId]);
            $targetFine = $fStmt->fetch();

            if (!$targetFine) {
                throw new RuntimeException('Fine not found or already settled.');
            }

            $amount = (float) $targetFine['amount'];
            $borrowerId = (int) $targetFine['borrower_id'];

            // Insert payment record
            $insPay = $pdo->prepare(
                "INSERT INTO payments (fine_id, user_id, amount, payment_method, transaction_ref, payment_type, payment_date)
                 VALUES (?, ?, ?, ?, ?, 'fine', NOW())"
            );
            $insPay->execute([$fineId, $borrowerId, $amount, $method, $deskRef]);

            // Update fine
            $pdo->prepare("UPDATE fines SET status = 'paid', paid_date = NOW() WHERE fine_id = ?")->execute([$fineId]);

            log_activity($pdo, $uid, 'DESK_FINE_COLLECT', "fine_id=$fineId amount=$amount method=$method ref=$deskRef borrower_id=$borrowerId");
            $pdo->commit();

            $msg = "✓ Collected fine of " . number_format($amount, 2) . " " . e(get_setting($pdo, 'currency', 'BDT')) . " via $method (Ref: $deskRef).";
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Error processing desk payment: ' . $ex->getMessage();
        }
    }
}

// Filters & Search
$filter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = 'SELECT f.*, b.title, u.full_name, u.email, bw.user_id AS borrower_id, c.barcode
        FROM fines f
        JOIN borrowings bw ON bw.borrowing_id = f.borrowing_id
        JOIN book_copies c ON c.copy_id = bw.copy_id
        JOIN books b       ON b.book_id = c.book_id
        JOIN users u       ON u.user_id = bw.user_id';
$cond = []; $params = [];

if (!$isStaff) {
    $cond[] = 'bw.user_id = ?';
    $params[] = $uid;
}

if (in_array($filter, ['pending', 'paid', 'waived'], true)) {
    $cond[] = 'f.status = ?';
    $params[] = $filter;
}

if ($search !== '' && $isStaff) {
    $cond[] = '(u.full_name LIKE ? OR u.email LIKE ? OR b.title LIKE ? OR c.barcode LIKE ?)';
    $sLike = "%$search%";
    array_push($params, $sLike, $sLike, $sLike, $sLike);
}

if ($cond) {
    $sql .= ' WHERE ' . implode(' AND ', $cond);
}

$sql .= ' ORDER BY f.status = "pending" DESC, f.imposed_date DESC LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Summary stats
if ($isStaff) {
    $summary = [
        'pending'   => (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'pending'")->fetchColumn(),
        'collected' => (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'paid'")->fetchColumn(),
        'waived'    => (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'waived'")->fetchColumn(),
        'count'     => (int) $pdo->query("SELECT COUNT(*) FROM fines")->fetchColumn(),
    ];
} else {
    $summary = [
        'pending'   => (float) $pdo->query("SELECT COALESCE(SUM(f.amount), 0) FROM fines f JOIN borrowings bw ON bw.borrowing_id = f.borrowing_id WHERE bw.user_id = $uid AND f.status = 'pending'")->fetchColumn(),
        'collected' => (float) $pdo->query("SELECT COALESCE(SUM(f.amount), 0) FROM fines f JOIN borrowings bw ON bw.borrowing_id = f.borrowing_id WHERE bw.user_id = $uid AND f.status = 'paid'")->fetchColumn(),
        'waived'    => (float) $pdo->query("SELECT COALESCE(SUM(f.amount), 0) FROM fines f JOIN borrowings bw ON bw.borrowing_id = f.borrowing_id WHERE bw.user_id = $uid AND f.status = 'waived'")->fetchColumn(),
    ];
}

$page = 'Fines';
include 'includes/header.php';
?>

<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
    <div>
      <h2>💰 <?= $isStaff ? 'Circulation Fines &amp; Penalties Management' : 'My Fines &amp; Payment Desk' ?></h2>
      <p class="sub" style="margin-bottom:0">
        Overdue rate: <strong><?= e(get_setting($pdo, 'fine_per_day', '5.00')) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?>/day</strong>
        &nbsp;•&nbsp; Grace period: <strong><?= e(get_setting($pdo, 'fine_grace_period_days', '2')) ?> days</strong>
        &nbsp;•&nbsp; Fine ceiling: <strong><?= e(get_setting($pdo, 'max_fine_per_book', '500.00')) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?></strong>
      </p>
    </div>
    <?php if ($isStaff): ?>
      <div>
        <a href="librarian_dashboard.php#fine-settings" class="btn-sm btn-secondary" style="text-decoration:none;">
          ⚙️ Fine &amp; Penalty Settings Desk
        </a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <!-- Summary Metric Tiles -->
  <div class="stat-row">
    <div class="stat">
      <b style="color:var(--danger);"><?= number_format($summary['pending'], 2) ?></b>
      <span>Pending Fines (<?= e(get_setting($pdo, 'currency', 'BDT')) ?>)</span>
    </div>
    <div class="stat">
      <b style="color:var(--ok);"><?= number_format($summary['collected'], 2) ?></b>
      <span>Collected Total</span>
    </div>
    <div class="stat">
      <b><?= number_format($summary['waived'], 2) ?></b>
      <span>Waived Total</span>
    </div>
    <?php if ($isStaff): ?>
      <div class="stat">
        <b><?= $summary['count'] ?></b>
        <span>Total Records</span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Filter & Search Toolbar -->
  <div class="toolbar">
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
      <?php foreach (['' => 'All Fines', 'pending' => 'Pending Only', 'paid' => 'Paid', 'waived' => 'Waived'] as $k => $v): ?>
        <a class="btn-sm <?= $filter === $k ? '' : 'btn-secondary' ?>" style="text-decoration:none;"
           href="fines.php<?= $k ? '?status=' . $k : '' ?>"><?= $v ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($isStaff): ?>
      <form method="get" action="fines.php">
        <?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
        <input type="text" name="q" placeholder="Search member, book, barcode..." value="<?= e($search) ?>" style="min-width:220px;">
        <button type="submit" class="btn-sm">Search</button>
        <?php if ($search !== ''): ?>
          <a href="fines.php<?= $filter ? '?status=' . $filter : '' ?>" class="btn-sm btn-secondary" style="text-decoration:none;">Clear</a>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>

  <!-- Fines Table -->
  <table>
    <thead>
      <tr>
        <th>Book &amp; Barcode</th>
        <?php if ($isStaff): ?><th>Member Name</th><?php endif; ?>
        <th>Reason</th>
        <th>Amount</th>
        <th>Imposed Date</th>
        <th>Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): 
        $st = $r['status'];
        $stClass = match($st) {
            'pending' => 'bad',
            'paid'    => 'ok',
            'waived'  => 'warn',
            default   => 'info'
        };
      ?>
        <tr>
          <td>
            <strong><?= e($r['title']) ?></strong><br>
            <code><?= e($r['barcode']) ?></code>
          </td>
          <?php if ($isStaff): ?>
            <td>
              <strong><?= e($r['full_name']) ?></strong><br>
              <small style="color:var(--muted);"><?= e($r['email']) ?></small>
            </td>
          <?php endif; ?>
          <td>
            <?= e($r['reason']) ?>
            <?php if ($st === 'waived' && !empty($r['waiver_reason'])): ?>
              <br><small style="color:var(--muted);font-style:italic;">Reason: <?= e($r['waiver_reason']) ?></small>
            <?php endif; ?>
          </td>
          <td style="font-weight:700;font-size:.95rem;">
            <?= number_format((float) $r['amount'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?>
          </td>
          <td><small><?= e(substr($r['imposed_date'], 0, 16)) ?></small></td>
          <td>
            <span class="tag <?= $stClass ?>">
              <?= $st === 'pending' ? 'Pending' : ($st === 'paid' ? '✓ Paid' : 'Waived') ?>
            </span>
            <?php if ($r['paid_date']): ?>
              <br><small style="color:var(--muted);font-size:.74rem;"><?= substr($r['paid_date'], 0, 10) ?></small>
            <?php endif; ?>
          </td>
          <td class="actions">
            <?php if ($st === 'pending' && !$isStaff): ?>
              <a href="payment.php?fine_id=<?= (int)$r['fine_id'] ?>" class="btn-sm btn-success" style="text-decoration:none;">
                💳 Pay (Demo)
              </a>
            <?php elseif ($st === 'paid'): ?>
              <a href="payment.php?fine_id=<?= (int)$r['fine_id'] ?>&receipt=1" class="btn-sm btn-secondary" style="text-decoration:none;">
                🧾 Receipt
              </a>
            <?php elseif ($st === 'pending' && $isStaff): ?>
              <!-- Settle Desk Cash Button -->
              <button type="button" class="btn-sm btn-success" onclick="openCashModal(<?= (int)$r['fine_id'] ?>, '<?= e(addslashes($r['full_name'])) ?>', <?= (float)$r['amount'] ?>)">
                💵 Collect
              </button>

              <!-- Waive with Justification Button -->
              <button type="button" class="btn-sm btn-secondary" onclick="openWaiveModal(<?= (int)$r['fine_id'] ?>, '<?= e(addslashes($r['full_name'])) ?>', <?= (float)$r['amount'] ?>)">
                Waive
              </button>
            <?php else: ?>
              –
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $isStaff ? 7 : 6 ?>" style="text-align:center;padding:24px;color:var(--muted);">No fines found matching criteria. 🎉</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Modal Dialog: In-Person Desk Cash Collection -->
<div id="cash-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;max-width:440px;width:90%;padding:26px;box-shadow:var(--shadow-lg);">
    <h3 style="color:var(--navy);font-size:1.1rem;margin-bottom:6px;">💵 Record Desk Fine Collection</h3>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px;" id="cash-modal-info">Collect in-person cash payment.</p>
    
    <form method="POST" action="fines.php">
      <input type="hidden" name="action" value="collect_cash">
      <input type="hidden" name="fine_id" id="cash-fine-id">

      <label>Collection Channel</label>
      <select name="payment_method" required>
        <option value="Desk Cash">Cash (Circulation Counter)</option>
        <option value="Campus Card POS">Campus ID Card / Student Account</option>
        <option value="Bank Deposit Slip">Bank Deposit Receipt</option>
      </select>

      <label>Desk Receipt Reference</label>
      <input type="text" name="desk_ref" id="cash-desk-ref" required>

      <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn-sm btn-secondary" onclick="closeModals()">Cancel</button>
        <button type="submit" class="btn-sm btn-success" style="width:auto;">✓ Mark Settled</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Dialog: Waive with Justification -->
<div id="waive-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;max-width:440px;width:90%;padding:26px;box-shadow:var(--shadow-lg);">
    <h3 style="color:var(--navy);font-size:1.1rem;margin-bottom:6px;">⚠️ Waive Library Fine</h3>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px;" id="waive-modal-info">Select a valid reason for this fine waiver.</p>
    
    <form method="POST" action="fines.php">
      <input type="hidden" name="action" value="waive">
      <input type="hidden" name="fine_id" id="waive-fine-id">

      <label>Authorized Reason / Justification *</label>
      <select name="waiver_reason" required>
        <option value="First-Time Courtesy Waiver">First-Time Courtesy Waiver</option>
        <option value="Medical Certificate / Sick Leave">Medical Certificate / Certified Sick Leave</option>
        <option value="Faculty / Dept Head Exemption">Faculty / Department Head Exemption</option>
        <option value="Library System Glitch / Holiday Closed">Library System Glitch / Holiday Closed</option>
        <option value="Dean Approval">Dean of Academic Affairs Approval</option>
      </select>

      <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn-sm btn-secondary" onclick="closeModals()">Cancel</button>
        <button type="submit" class="btn-sm btn-danger" style="width:auto;" onclick="return confirm('Confirm fine waiver? This will be permanently recorded in audit logs.');">Confirm Waiver</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCashModal(fineId, memberName, amount) {
  document.getElementById('cash-fine-id').value = fineId;
  document.getElementById('cash-modal-info').textContent = 'Collecting ' + amount.toFixed(2) + ' BDT from ' + memberName;
  document.getElementById('cash-desk-ref').value = 'DESK-CASH-' + Math.floor(100000 + Math.random() * 900000);
  var modal = document.getElementById('cash-modal');
  modal.style.display = 'flex';
}

function openWaiveModal(fineId, memberName, amount) {
  document.getElementById('waive-fine-id').value = fineId;
  document.getElementById('waive-modal-info').textContent = 'Waiving fine of ' + amount.toFixed(2) + ' BDT for ' + memberName;
  var modal = document.getElementById('waive-modal');
  modal.style.display = 'flex';
}

function closeModals() {
  document.getElementById('cash-modal').style.display = 'none';
  document.getElementById('waive-modal').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>