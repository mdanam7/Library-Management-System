<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
// Librarian Desk: accessible by librarian role. Admin can also visit via navigation.
require_staff();

$uid = (int) $_SESSION['user_id'];
$role = current_role();
$libName = $_SESSION['full_name'] ?? 'Librarian';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
$activeTab = $_GET['tab'] ?? 'circulation';

// Handle Librarian Desk Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Quick Barcode Return / Check-in
    if ($action === 'quick_return') {
        $barcode = trim($_POST['barcode'] ?? '');
        if (!$barcode) {
            $err = 'Please enter or scan a book copy barcode.';
        } else {
            try {
                $pdo->beginTransaction();

                // Find active borrowing by copy barcode
                $stmt = $pdo->prepare(
                    "SELECT bw.*, c.copy_id, c.book_id, c.barcode, b.title, u.full_name
                     FROM book_copies c
                     JOIN borrowings bw ON bw.copy_id = c.copy_id
                     JOIN books b ON b.book_id = c.book_id
                     JOIN users u ON u.user_id = bw.user_id
                     WHERE c.barcode = ? AND bw.return_date IS NULL
                     FOR UPDATE"
                );
                $stmt->execute([$barcode]);
                $bw = $stmt->fetch();

                if (!$bw) {
                    throw new RuntimeException("No active borrowing record found for barcode: '$barcode'.");
                }

                $borrowingId = (int) $bw['borrowing_id'];
                $days = overdue_days($bw['due_date']);
                $fineCalc = calculate_fine($pdo, $days);
                $fineAmount = $fineCalc['amount'];

                // Mark return date
                $pdo->prepare("UPDATE borrowings SET return_date = CURDATE() WHERE borrowing_id = ?")->execute([$borrowingId]);

                // Auto-generate fine if late
                if ($fineAmount > 0) {
                    $reason = "Late return by {$fineCalc['days_late']} day(s) ({$fineCalc['billable_days']} billable after grace period)";
                    if ($fineCalc['capped']) {
                        $reason .= " [Capped at ceiling]";
                    }
                    $stmtFine = $pdo->prepare("INSERT INTO fines (borrowing_id, amount, reason, imposed_by) VALUES (?, ?, ?, ?)");
                    $stmtFine->execute([$borrowingId, $fineAmount, $reason, $uid]);
                }

                // Update copy status (check if pending reservation exists)
                $resStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE book_id = ? AND status = 'pending'");
                $resStmt->execute([(int) $bw['book_id']]);
                $newCopyStatus = ((int) $resStmt->fetchColumn() > 0) ? 'reserved' : 'available';

                $pdo->prepare("UPDATE book_copies SET status = ? WHERE copy_id = ?")->execute([$newCopyStatus, (int) $bw['copy_id']]);

                log_activity($pdo, $uid, 'LIBRARIAN_CHECKIN', "barcode=$barcode borrowing_id=$borrowingId fine=$fineAmount");
                $pdo->commit();

                $curr = e(get_setting($pdo, 'currency', 'BDT'));
                $msg = "✓ Successfully checked in '{$bw['title']}' (Barcode: {$bw['barcode']}). " .
                       ($fineAmount > 0 ? "Late by {$fineCalc['days_late']} day(s) — Fine of " . number_format($fineAmount, 2) . " $curr auto-imposed." : "Returned on time. No fine.");
                $activeTab = 'circulation';
            } catch (RuntimeException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = $ex->getMessage();
                $activeTab = 'circulation';
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = 'Database error processing barcode check-in.';
                $activeTab = 'circulation';
            }
        }
    }

    // Action 2: Fulfill Reservation & Issue Copy
    elseif ($action === 'fulfill_reservation') {
        $resId = (int) ($_POST['reservation_id'] ?? 0);
        $copyId = (int) ($_POST['copy_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $resStmt = $pdo->prepare("SELECT * FROM reservations WHERE reservation_id = ? AND status = 'pending' FOR UPDATE");
            $resStmt->execute([$resId]);
            $res = $resStmt->fetch();

            if (!$res) throw new RuntimeException("Pending reservation record not found.");

            $memberId = (int) $res['user_id'];
            $membership = user_membership($pdo, $memberId);
            if (!$membership) throw new RuntimeException("Member has no active membership.");

            if (is_borrowing_suspended($pdo, $memberId)) {
                $thresh = get_setting($pdo, 'borrow_suspension_threshold', '100.00');
                throw new RuntimeException("Borrowing blocked: Member has overdue unpaid fines exceeding threshold of $thresh BDT.");
            }

            if (active_borrow_count($pdo, $memberId) >= (int) $membership['max_books']) {
                throw new RuntimeException("Member has reached their limit of {$membership['max_books']} active book(s).");
            }

            // Lock copy
            $copyStmt = $pdo->prepare("SELECT * FROM book_copies WHERE copy_id = ? AND status IN ('available', 'reserved') FOR UPDATE");
            $copyStmt->execute([$copyId]);
            $copy = $copyStmt->fetch();

            if (!$copy) throw new RuntimeException("Selected copy is not ready for issue.");

            $loanDays = (int) $membership['loan_days'];
            $dueDate = date('Y-m-d', strtotime("+$loanDays days"));

            // Insert borrowing
            $pdo->prepare("INSERT INTO borrowings (user_id, copy_id, issue_date, due_date, issued_by) VALUES (?, ?, CURDATE(), ?, ?)")
                ->execute([$memberId, $copyId, $dueDate, $uid]);

            // Mark reservation as fulfilled
            $pdo->prepare("UPDATE reservations SET status = 'fulfilled' WHERE reservation_id = ?")->execute([$resId]);

            // Update copy status to borrowed
            $pdo->prepare("UPDATE book_copies SET status = 'borrowed' WHERE copy_id = ?")->execute([$copyId]);

            log_activity($pdo, $uid, 'FULFILL_RESERVATION', "res_id=$resId copy_id=$copyId user_id=$memberId");
            $pdo->commit();

            $msg = "✓ Reservation fulfilled and book issued to member. Due date: $dueDate.";
            $activeTab = 'circulation';
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $ex->getMessage();
            $activeTab = 'circulation';
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Error fulfilling reservation.';
            $activeTab = 'circulation';
        }
    }

    // Action 3: Update Copy Status
    elseif ($action === 'update_copy_status') {
        $copyId = (int) ($_POST['copy_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $validStatuses = ['available', 'maintenance', 'lost'];

        if (in_array($newStatus, $validStatuses, true)) {
            $pdo->prepare("UPDATE book_copies SET status = ? WHERE copy_id = ? AND status <> 'borrowed'")->execute([$newStatus, $copyId]);
            log_activity($pdo, $uid, 'UPDATE_COPY_STATUS', "copy_id=$copyId status=$newStatus");
            $msg = "✓ Copy status updated to " . strtoupper($newStatus) . ".";
        } else {
            $err = 'Invalid status specified or copy is currently borrowed.';
        }
        $activeTab = 'inventory';
    }

    // Action 4: Librarian Settings - Update Fine & Penalty Rules (Requirement 5)
    elseif ($action === 'update_fine_settings') {
        $finePerDay    = (float) ($_POST['fine_per_day'] ?? 5.00);
        $graceDays     = (int) ($_POST['fine_grace_period_days'] ?? 2);
        $maxFine       = (float) ($_POST['max_fine_per_book'] ?? 500.00);
        $lostBookFee   = (float) ($_POST['lost_book_fee'] ?? 400.00);
        $damagedFee    = (float) ($_POST['damaged_book_fee'] ?? 150.00);
        $suspendThresh = (float) ($_POST['borrow_suspension_threshold'] ?? 100.00);

        set_setting($pdo, 'fine_per_day', number_format($finePerDay, 2, '.', ''), 'Fine amount per day for late book return');
        set_setting($pdo, 'fine_grace_period_days', (string)$graceDays, 'Grace period days before fines start accumulating');
        set_setting($pdo, 'max_fine_per_book', number_format($maxFine, 2, '.', ''), 'Maximum fine ceiling per book');
        set_setting($pdo, 'lost_book_fee', number_format($lostBookFee, 2, '.', ''), 'Standard replacement fee for lost book');
        set_setting($pdo, 'damaged_book_fee', number_format($damagedFee, 2, '.', ''), 'Standard penalty for damaged book');
        set_setting($pdo, 'borrow_suspension_threshold', number_format($suspendThresh, 2, '.', ''), 'Fine amount threshold where borrowing privileges are suspended');

        log_activity($pdo, $uid, 'UPDATE_FINE_SETTINGS', "fine_per_day=$finePerDay grace=$graceDays max=$maxFine suspend=$suspendThresh");
        $msg = "✓ Fine & Penalty policies and rules updated successfully!";
        $activeTab = 'fines';
    }

    // Action 5: Manual Fine / Penalty Assessment (Requirement 5)
    elseif ($action === 'assess_manual_fine') {
        $targetBorrowingId = (int) ($_POST['borrowing_id'] ?? 0);
        $reasonCategory    = trim($_POST['reason_category'] ?? 'Damaged Book');
        $customDetails     = trim($_POST['custom_details'] ?? '');
        $amount            = (float) ($_POST['amount'] ?? 0.00);

        if ($targetBorrowingId <= 0 || $amount <= 0) {
            $err = 'Please select a borrowing record and enter a valid fine amount.';
            $activeTab = 'fines';
        } else {
            $bwStmt = $pdo->prepare("SELECT borrowing_id, user_id FROM borrowings WHERE borrowing_id = ?");
            $bwStmt->execute([$targetBorrowingId]);
            $targetBw = $bwStmt->fetch();

            if (!$targetBw) {
                $err = 'Selected borrowing loan was not found.';
                $activeTab = 'fines';
            } else {
                $finalReason = $reasonCategory . ($customDetails ? " ($customDetails)" : "");
                $insFine = $pdo->prepare("INSERT INTO fines (borrowing_id, amount, reason, imposed_by) VALUES (?, ?, ?, ?)");
                $insFine->execute([$targetBorrowingId, $amount, $finalReason, $uid]);

                log_activity($pdo, $uid, 'MANUAL_FINE_ASSESS', "borrowing_id=$targetBorrowingId amount=$amount reason=$finalReason");
                $msg = "✓ Manual fine of " . number_format($amount, 2) . " " . e(get_setting($pdo, 'currency', 'BDT')) . " imposed successfully for: $finalReason.";
                $activeTab = 'fines';
            }
        }
    }
}

// Operational KPIs
$todayIssuedCount   = (int) $pdo->query("SELECT COUNT(*) FROM borrowings WHERE issue_date = CURDATE()")->fetchColumn();
$todayReturnedCount = (int) $pdo->query("SELECT COUNT(*) FROM borrowings WHERE return_date = CURDATE()")->fetchColumn();
$overdueCount       = (int) $pdo->query("SELECT COUNT(*) FROM borrowings WHERE return_date IS NULL AND due_date < CURDATE()")->fetchColumn();
$pendingResCount    = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
$maintenanceCount   = (int) $pdo->query("SELECT COUNT(*) FROM book_copies WHERE status IN ('maintenance', 'lost')")->fetchColumn();
$totalPendingFines  = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'pending'")->fetchColumn();

// Pending Reservations
$pendingReservations = $pdo->query(
    "SELECT r.*, b.title, b.book_id, u.full_name, u.email
     FROM reservations r
     JOIN books b ON b.book_id = r.book_id
     JOIN users u ON u.user_id = r.user_id
     WHERE r.status = 'pending'
     ORDER BY r.reservation_date ASC"
)->fetchAll();

// Overdue Loans
$overdueLoans = $pdo->query(
    "SELECT bw.borrowing_id, bw.due_date, DATEDIFF(CURDATE(), bw.due_date) AS days_late,
            u.full_name, u.email, b.title, c.barcode
     FROM borrowings bw
     JOIN users u ON u.user_id = bw.user_id
     JOIN book_copies c ON c.copy_id = bw.copy_id
     JOIN books b ON b.book_id = c.book_id
     WHERE bw.return_date IS NULL AND bw.due_date < CURDATE()
     ORDER BY days_late DESC LIMIT 20"
)->fetchAll();

// Active borrowings for manual penalty assessment dropdown
$activeLoansForFine = $pdo->query(
    "SELECT bw.borrowing_id, u.full_name, b.title, c.barcode, bw.issue_date
     FROM borrowings bw
     JOIN users u ON u.user_id = bw.user_id
     JOIN book_copies c ON c.copy_id = bw.copy_id
     JOIN books b ON b.book_id = c.book_id
     ORDER BY bw.issue_date DESC LIMIT 50"
)->fetchAll();

// Suspended / Defaulting Members (Unpaid fines >= threshold)
$suspensionThreshold = (float) get_setting($pdo, 'borrow_suspension_threshold', '100.00');
$defaultingMembers = $pdo->query(
    "SELECT u.user_id, u.full_name, u.email, SUM(f.amount) AS total_unpaid, COUNT(f.fine_id) AS fine_count
     FROM users u
     JOIN borrowings bw ON bw.user_id = u.user_id
     JOIN fines f ON f.borrowing_id = bw.borrowing_id
     WHERE f.status = 'pending'
     GROUP BY u.user_id, u.full_name, u.email
     HAVING total_unpaid >= $suspensionThreshold
     ORDER BY total_unpaid DESC"
)->fetchAll();

// Copy inventory list
$copyList = $pdo->query(
    "SELECT c.*, b.title FROM book_copies c
     JOIN books b ON b.book_id = c.book_id
     ORDER BY FIELD(c.status, 'maintenance', 'lost', 'reserved', 'borrowed', 'available'), c.barcode ASC LIMIT 30"
)->fetchAll();

// Desk Activity Logs
$deskLogs = $pdo->query(
    "SELECT l.*, u.full_name FROM activity_logs l
     LEFT JOIN users u ON u.user_id = l.user_id
     WHERE l.action IN ('LIBRARIAN_CHECKIN', 'RETURN', 'RETURN_BARCODE', 'ISSUE', 'FULFILL_RESERVATION', 'UPDATE_COPY_STATUS', 'UPDATE_FINE_SETTINGS', 'MANUAL_FINE_ASSESS', 'FINE_WAIVE', 'DESK_FINE_COLLECT')
     ORDER BY l.created_at DESC LIMIT 25"
)->fetchAll();

$page = 'Librarian Desk';
include 'includes/header.php';
?>

<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
    <div>
      <h2>📖 Librarian Desk &amp; Circulation Controls</h2>
      <p class="sub" style="margin-bottom:0">
        Welcome, <strong><?= htmlspecialchars(explode(' ', trim($libName))[0]) ?></strong> — Operations hub: issue/return circulation, fine assessments, inventory status, and reservation fulfillment.
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="borrow.php" class="btn-sm" style="text-decoration:none;">➕ Issue Book</a>
      <a href="borrowings.php" class="btn-sm btn-secondary" style="text-decoration:none;">📋 Borrowings Registry</a>
      <a href="books.php" class="btn-sm btn-secondary" style="text-decoration:none;">📚 Book Catalog</a>
      <?php if ($role === 'admin'): ?>
        <a href="admin_dashboard.php" class="btn-sm btn-secondary" style="text-decoration:none;">⚙️ Admin Panel</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <!-- Circulation KPIs -->
  <div class="stat-row">
    <div class="stat"><b><?= $todayIssuedCount ?></b><span>Issued Today</span></div>
    <div class="stat"><b><?= $todayReturnedCount ?></b><span>Returned Today</span></div>
    <div class="stat"><b style="color:var(--danger);"><?= $overdueCount ?></b><span>Overdue Loans</span></div>
    <div class="stat"><b style="color:var(--accent);"><?= $pendingResCount ?></b><span>Reservations</span></div>
    <div class="stat"><b style="color:#d97706;"><?= $maintenanceCount ?></b><span>Maintenance/Lost</span></div>
    <div class="stat"><b style="color:var(--danger);"><?= number_format($totalPendingFines, 2) ?></b><span>Pending Fines</span></div>
  </div>

  <!-- Librarian Desk Tabs -->
  <div class="nav-tabs" style="margin-bottom:24px;">
    <a href="librarian_dashboard.php?tab=circulation" class="<?= $activeTab === 'circulation' ? 'active' : '' ?>">
      ⚡ Express Circulation Desk
    </a>
    <a href="librarian_dashboard.php?tab=fines" class="<?= $activeTab === 'fines' ? 'active' : '' ?>" id="fine-settings">
      💰 Fine &amp; Penalty Controls (Settings &amp; Desk)
    </a>
    <a href="librarian_dashboard.php?tab=inventory" class="<?= $activeTab === 'inventory' ? 'active' : '' ?>">
      🛠️ Copy Status &amp; Maintenance
    </a>
    <a href="librarian_dashboard.php?tab=logs" class="<?= $activeTab === 'logs' ? 'active' : '' ?>">
      📜 Desk Activity Audit
    </a>
  </div>

  <!-- TAB 1: EXPRESS CIRCULATION DESK -->
  <?php if ($activeTab === 'circulation'): ?>
    <!-- Express Barcode Scanner Box -->
    <div style="background:#f8fafc;border:2px solid var(--border);border-radius:12px;padding:24px;margin-bottom:26px;">
      <h3 style="color:var(--navy);font-size:1.1rem;margin-bottom:6px;">⚡ Express Barcode Return / Check-In</h3>
      <p style="font-size:.86rem;color:var(--muted);margin-bottom:14px;">
        Scan or enter book copy barcode to process the check-in immediately. Fines are calculated automatically respecting grace period and policy limits.
      </p>

      <form method="post" action="librarian_dashboard.php?tab=circulation" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="action" value="quick_return">
        <input type="text" name="barcode" placeholder="Enter barcode (e.g. BC-0001)" required style="flex:1;min-width:240px;margin:0;">
        <button type="submit" class="btn-sm btn-success" style="padding:10px 22px;">Check-In &amp; Return Book</button>
      </form>
    </div>

    <!-- Reservation Queue Desk -->
    <div style="margin-top:20px;">
      <h3 style="color:var(--navy);margin-bottom:8px;">📌 Reservation Fulfillment Queue (<?= count($pendingReservations) ?> Pending)</h3>
      <table>
        <thead>
          <tr>
            <th>Book Title</th>
            <th>Member Details</th>
            <th>Requested Date</th>
            <th>Available Copy</th>
            <th>Fulfill Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingReservations as $pr): 
            $cStmt = $pdo->prepare("SELECT copy_id, barcode FROM book_copies WHERE book_id = ? AND status IN ('available', 'reserved') LIMIT 1");
            $cStmt->execute([(int) $pr['book_id']]);
            $availCopy = $cStmt->fetch();
          ?>
            <tr>
              <td><strong><?= e($pr['title']) ?></strong></td>
              <td>
                <strong><?= e($pr['full_name']) ?></strong><br>
                <small style="color:var(--muted);"><?= e($pr['email']) ?></small>
              </td>
              <td><?= e($pr['reservation_date']) ?></td>
              <td>
                <?php if ($availCopy): ?>
                  <span class="tag ok">Barcode: <?= e($availCopy['barcode']) ?></span>
                <?php else: ?>
                  <span class="tag warn">Awaiting return</span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <?php if ($availCopy): ?>
                  <form method="post" action="librarian_dashboard.php?tab=circulation" style="display:inline;">
                    <input type="hidden" name="action" value="fulfill_reservation">
                    <input type="hidden" name="reservation_id" value="<?= (int) $pr['reservation_id'] ?>">
                    <input type="hidden" name="copy_id" value="<?= (int) $availCopy['copy_id'] ?>">
                    <button type="submit" class="btn-sm btn-success" onclick="return confirm('Fulfill reservation and issue barcode <?= e($availCopy['barcode']) ?> to <?= e($pr['full_name']) ?>?')">
                      Fulfill &amp; Issue
                    </button>
                  </form>
                <?php else: ?>
                  <span style="font-size:.8rem;color:var(--muted);">No copy ready</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($pendingReservations)): ?>
            <tr><td colspan="5">No pending book reservations at this time. 🎉</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Overdue Loans Table -->
    <div style="margin-top:30px;">
      <h3 style="color:var(--navy);margin-bottom:8px;">⚠ Overdue Loans Follow-Up</h3>
      <table>
        <thead>
          <tr>
            <th>Member</th>
            <th>Book Title</th>
            <th>Barcode</th>
            <th>Due Date</th>
            <th>Overdue Days</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($overdueLoans as $ol): ?>
            <tr>
              <td>
                <strong><?= e($ol['full_name']) ?></strong><br>
                <small style="color:var(--muted);"><?= e($ol['email']) ?></small>
              </td>
              <td><?= e($ol['title']) ?></td>
              <td><code><?= e($ol['barcode']) ?></code></td>
              <td class="overdue"><?= e($ol['due_date']) ?></td>
              <td class="overdue"><?= (int) $ol['days_late'] ?> day(s)</td>
              <td class="actions">
                <form method="post" action="librarian_dashboard.php?tab=circulation" style="display:inline;">
                  <input type="hidden" name="action" value="quick_return">
                  <input type="hidden" name="barcode" value="<?= e($ol['barcode']) ?>">
                  <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Process return for barcode <?= e($ol['barcode']) ?>?')">
                    Process Return
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($overdueLoans)): ?>
            <tr><td colspan="6">No overdue loans found. All loans are within due dates! 🎉</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- TAB 2: FINE & PENALTY SETTINGS & CONTROLS (REQUIREMENT 5) -->
  <?php if ($activeTab === 'fines'): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px;">
      <!-- Section 5.1: Librarian Settings - Fine/Penalty Policies -->
      <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:12px;padding:24px;">
        <h3 style="color:var(--navy);font-size:1.1rem;margin-bottom:6px;">⚙️ Fine &amp; Penalty Policy Settings</h3>
        <p class="sub" style="margin-bottom:16px;">Configure circulation penalty rates, grace thresholds, and suspension ceilings.</p>

        <form method="POST" action="librarian_dashboard.php?tab=fines">
          <input type="hidden" name="action" value="update_fine_settings">

          <div class="form-row">
            <div class="form-group">
              <label>Daily Fine Rate (BDT / Day)</label>
              <input type="number" step="0.50" name="fine_per_day" value="<?= e(get_setting($pdo, 'fine_per_day', '5.00')) ?>" required>
            </div>
            <div class="form-group">
              <label>Grace Period (Days)</label>
              <input type="number" name="fine_grace_period_days" value="<?= e(get_setting($pdo, 'fine_grace_period_days', '2')) ?>" min="0" max="14" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Max Fine Ceiling per Book (BDT)</label>
              <input type="number" step="10.00" name="max_fine_per_book" value="<?= e(get_setting($pdo, 'max_fine_per_book', '500.00')) ?>" required>
            </div>
            <div class="form-group">
              <label>Suspension Threshold (BDT)</label>
              <input type="number" step="10.00" name="borrow_suspension_threshold" value="<?= e(get_setting($pdo, 'borrow_suspension_threshold', '100.00')) ?>" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Lost Book Replacement Charge</label>
              <input type="number" step="10.00" name="lost_book_fee" value="<?= e(get_setting($pdo, 'lost_book_fee', '400.00')) ?>" required>
            </div>
            <div class="form-group">
              <label>Damaged Book Penalty (BDT)</label>
              <input type="number" step="10.00" name="damaged_book_fee" value="<?= e(get_setting($pdo, 'damaged_book_fee', '150.00')) ?>" required>
            </div>
          </div>

          <button type="submit" class="btn-sm" style="margin-top:14px;padding:9px 20px;">Save Fine Policies</button>
        </form>
      </div>

      <!-- Section 5.2: Manual Fine Assessment Desk -->
      <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:12px;padding:24px;">
        <h3 style="color:var(--navy);font-size:1.1rem;margin-bottom:6px;">⚖️ Manual Fine &amp; Damage Assessment</h3>
        <p class="sub" style="margin-bottom:16px;">Impose penalty for damaged binding, torn pages, lost items, or library misconduct.</p>

        <form method="POST" action="librarian_dashboard.php?tab=fines">
          <input type="hidden" name="action" value="assess_manual_fine">

          <label>Select Borrowing Record *</label>
          <select name="borrowing_id" required>
            <option value="">— Select Loan —</option>
            <?php foreach ($activeLoansForFine as $alf): ?>
              <option value="<?= (int)$alf['borrowing_id'] ?>">
                #<?= (int)$alf['borrowing_id'] ?>: <?= e($alf['full_name']) ?> — "<?= e($alf['title']) ?>" (<?= e($alf['barcode']) ?>)
              </option>
            <?php endforeach; ?>
          </select>

          <div class="form-row" style="margin-top:10px;">
            <div class="form-group">
              <label>Penalty Reason Category</label>
              <select name="reason_category" id="manual-fine-cat" onchange="autoSuggestFineAmount(this.value)">
                <option value="Damaged Book (Pages/Binding)">Damaged Book (Pages/Binding)</option>
                <option value="Lost Book Replacement">Lost Book Replacement</option>
                <option value="Late Return Adjustment">Late Return Manual Adjustment</option>
                <option value="Unauthorized Item Removal">Unauthorized Item Removal</option>
                <option value="Other Misconduct">Other Policy Violation</option>
              </select>
            </div>
            <div class="form-group">
              <label>Assessed Amount (BDT) *</label>
              <input type="number" step="1.00" name="amount" id="manual-fine-amount" value="150.00" required>
            </div>
          </div>

          <label>Specific Incident Details / Notes</label>
          <input type="text" name="custom_details" placeholder="e.g. Water damage on chapters 3-4, spine cracked">

          <button type="submit" class="btn-sm btn-danger" style="margin-top:14px;padding:9px 20px;">
            ⚖️ Impose Penalty Fine
          </button>
        </form>
      </div>
    </div>

    <!-- Section 5.3: Members with Suspended Borrowing Privileges -->
    <div style="margin-top:20px;margin-bottom:28px;">
      <h3 style="color:var(--navy);font-size:1.1rem;margin-bottom:8px;">
        🚫 Members Flagged for High Fines (≥ <?= number_format($suspensionThreshold, 2) ?> BDT)
      </h3>
      <p class="sub">These members have borrowing privileges automatically restricted until dues are settled.</p>

      <table>
        <thead>
          <tr>
            <th>Member Name</th>
            <th>Email</th>
            <th>Unpaid Fines Total</th>
            <th>Count</th>
            <th>Borrowing Status</th>
            <th>Quick Clearance</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($defaultingMembers as $dm): ?>
            <tr>
              <td><strong><?= e($dm['full_name']) ?></strong></td>
              <td><?= e($dm['email']) ?></td>
              <td><strong style="color:var(--danger);"><?= number_format((float)$dm['total_unpaid'], 2) ?> BDT</strong></td>
              <td><?= (int)$dm['fine_count'] ?> pending fine(s)</td>
              <td><span class="tag bad">🚫 Borrowing Restricted</span></td>
              <td>
                <a href="fines.php?q=<?= urlencode($dm['email']) ?>" class="btn-sm btn-secondary" style="text-decoration:none;">
                  Review &amp; Settle Dues
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($defaultingMembers)): ?>
            <tr><td colspan="6">No members currently exceed the unpaid fine suspension limit. 🎉</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Link to full Fine Register -->
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
      <div>
        <strong style="color:#1e40af;">Looking for the complete fine registry or in-person cash collection?</strong>
        <p style="font-size:.85rem;color:#3b82f6;margin:0;">Manage waivers, print receipts, and record counter payments in the Fine Register.</p>
      </div>
      <a href="fines.php" class="btn-sm" style="text-decoration:none;">Go to Complete Fines Register →</a>
    </div>
  <?php endif; ?>

  <!-- TAB 3: INVENTORY & COPY STATUS -->
  <?php if ($activeTab === 'inventory'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;">
      <div>
        <h3>🛠️ Book Copy Status &amp; Maintenance</h3>
        <p class="sub" style="margin-bottom:0">Update physical status of copies (Maintenance, Lost, Available).</p>
      </div>
      <a href="books.php" class="btn-sm btn-secondary" style="text-decoration:none;">📚 Browse Full Catalog</a>
    </div>

    <table>
      <thead>
        <tr>
          <th>Barcode</th>
          <th>Book Title</th>
          <th>Location</th>
          <th>Current Status</th>
          <th>Update Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($copyList as $cp): 
          $cTag = match($cp['status']) {
              'available' => 'ok',
              'borrowed'  => 'info',
              'reserved'  => 'warn',
              'maintenance', 'lost' => 'bad',
              default => 'info'
          };
        ?>
          <tr>
            <td><code><?= e($cp['barcode']) ?></code></td>
            <td><strong><?= e($cp['title']) ?></strong></td>
            <td><?= e($cp['location'] ?: 'Unassigned') ?></td>
            <td><span class="tag <?= $cTag ?>"><?= e(strtoupper($cp['status'])) ?></span></td>
            <td class="actions">
              <?php if ($cp['status'] !== 'borrowed'): ?>
                <form method="post" action="librarian_dashboard.php?tab=inventory" style="display:flex;gap:6px;align-items:center;">
                  <input type="hidden" name="action" value="update_copy_status">
                  <input type="hidden" name="copy_id" value="<?= (int) $cp['copy_id'] ?>">
                  <select name="new_status" style="width:auto;padding:5px 8px;font-size:.8rem;margin:0;">
                    <option value="available" <?= $cp['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="maintenance" <?= $cp['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="lost" <?= $cp['status'] === 'lost' ? 'selected' : '' ?>>Lost</option>
                  </select>
                  <button type="submit" class="btn-sm btn-secondary" style="padding:5px 10px;">Save</button>
                </form>
              <?php else: ?>
                <small style="color:var(--muted);">Currently borrowed</small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- TAB 4: DESK ACTIVITY LOGS -->
  <?php if ($activeTab === 'logs'): ?>
    <h3 style="margin-bottom:8px;">📜 Circulation &amp; Desk Audit Logs</h3>
    <p class="sub">Real-time audit trail of all desk operations, check-ins, fine assessments, and copy modifications.</p>

    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Staff / User</th>
          <th>Action</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($deskLogs as $dl): ?>
          <tr>
            <td><small><?= e($dl['created_at']) ?></small></td>
            <td><strong><?= e($dl['full_name'] ?: 'System') ?></strong></td>
            <td><span class="tag info"><?= e($dl['action']) ?></span></td>
            <td><code><?= e($dl['details']) ?></code></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($deskLogs)): ?>
          <tr><td colspan="4">No recent desk activity logs.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div>

<script>
function autoSuggestFineAmount(category) {
  var amtInput = document.getElementById('manual-fine-amount');
  if (category.indexOf('Lost') !== -1) {
    amtInput.value = '<?= e(get_setting($pdo, 'lost_book_fee', '400.00')) ?>';
  } else if (category.indexOf('Damage') !== -1) {
    amtInput.value = '<?= e(get_setting($pdo, 'damaged_book_fee', '150.00')) ?>';
  } else {
    amtInput.value = '50.00';
  }
}
</script>

<?php include 'includes/footer.php'; ?>
