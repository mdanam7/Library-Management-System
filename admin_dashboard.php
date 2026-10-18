<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_admin();

$uid = (int) $_SESSION['user_id'];
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
$activeTab = $_GET['tab'] ?? 'overview';

// Handle Admin POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Create New User Account (Admin/Librarian/Member)
    if ($action === 'create_user') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'member';

        if (!$fullName || !$email || !$password) {
            $err = 'Please fill in all required fields (Name, Email, Password).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please provide a valid email address.';
        } elseif (!in_array($role, ['admin', 'librarian', 'member'], true)) {
            $err = 'Invalid user role selected.';
        } else {
            try {
                $pdo->beginTransaction();

                // Check duplicate email
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->fetchColumn() > 0) {
                    throw new RuntimeException('Email address is already registered.');
                }

                $pwdHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, "active")');
                $stmt->execute([$fullName, $email, $pwdHash, $role]);
                $newUserId = (int) $pdo->lastInsertId();

                // If role is member, assign default membership tier (Regular)
                if ($role === 'member') {
                    $mTypeStmt = $pdo->query("SELECT membership_type_id FROM membership_types ORDER BY membership_type_id ASC LIMIT 1");
                    $mTypeId = (int) $mTypeStmt->fetchColumn();
                    if ($mTypeId > 0) {
                        $pdo->prepare("INSERT INTO memberships (user_id, membership_type_id, start_date, status) VALUES (?, ?, CURDATE(), 'active')")
                            ->execute([$newUserId, $mTypeId]);
                    }
                }

                log_activity($pdo, $uid, 'ADMIN_CREATE_USER', "created user_id=$newUserId email=$email role=$role");
                $pdo->commit();
                $msg = "✓ Account successfully created for '$fullName' ($email) with role: " . strtoupper($role);
                $activeTab = ($role === 'librarian') ? 'librarians' : 'users';
            } catch (RuntimeException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = $ex->getMessage();
                $activeTab = 'users';
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = 'Failed to create user account.';
                $activeTab = 'users';
            }
        }
    }

    // Action 2: Change User Role
    elseif ($action === 'update_role') {
        $targetUid = (int) ($_POST['target_user_id'] ?? 0);
        $newRole   = $_POST['role'] ?? '';

        if ($targetUid === $uid) {
            $err = 'You cannot change your own role.';
            $activeTab = 'users';
        } elseif (in_array($newRole, ['admin', 'librarian', 'member'], true)) {
            $pdo->prepare('UPDATE users SET role = ? WHERE user_id = ?')->execute([$newRole, $targetUid]);
            log_activity($pdo, $uid, 'UPDATE_USER_ROLE', "target_user_id=$targetUid new_role=$newRole");
            $msg = "✓ User role updated to " . strtoupper($newRole) . ".";
            $activeTab = 'users';
        }
    }

    // Action 3: Toggle User Account Status (active / suspended)
    elseif ($action === 'toggle_status') {
        $targetUid = (int) ($_POST['target_user_id'] ?? 0);
        $redirectTab = $_POST['redirect_tab'] ?? 'users';

        if ($targetUid === $uid) {
            $err = 'You cannot suspend your own admin account.';
            $activeTab = $redirectTab;
        } else {
            $stmt = $pdo->prepare('SELECT status, full_name, role FROM users WHERE user_id = ?');
            $stmt->execute([$targetUid]);
            $targetUser = $stmt->fetch();

            if ($targetUser) {
                $newStatus = ($targetUser['status'] === 'active') ? 'suspended' : 'active';
                $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$newStatus, $targetUid]);
                log_activity($pdo, $uid, 'TOGGLE_USER_STATUS', "target_user_id=$targetUid new_status=$newStatus");
                $msg = "✓ Account status for '{$targetUser['full_name']}' changed to " . strtoupper($newStatus) . ".";
            }
            $activeTab = $redirectTab;
        }
    }

    // Action 4: Approve Premium Membership Request (Requirement 3)
    elseif ($action === 'approve_premium_request') {
        $reqId = (int) ($_POST['request_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $rStmt = $pdo->prepare("SELECT * FROM membership_requests WHERE request_id = ? AND status = 'pending' FOR UPDATE");
            $rStmt->execute([$reqId]);
            $request = $rStmt->fetch();

            if (!$request) {
                throw new RuntimeException('Pending application not found or already processed.');
            }

            $targetUserId = (int) $request['user_id'];
            $premTypeId   = (int) $request['membership_type_id'];
            $months       = (int) $request['duration_months'];
            $fee          = (float) $request['fee'];

            // 1. Mark request as approved
            $updReq = $pdo->prepare("UPDATE membership_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?");
            $updReq->execute([$uid, $reqId]);

            // 2. Deactivate previous active memberships for this user
            $pdo->prepare("UPDATE memberships SET status = 'expired', end_date = CURDATE() WHERE user_id = ? AND status = 'active'")
                ->execute([$targetUserId]);

            // 3. Insert new Premium membership
            $insMem = $pdo->prepare(
                "INSERT INTO memberships (user_id, membership_type_id, start_date, end_date, status)
                 VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'active')"
            );
            $insMem->execute([$targetUserId, $premTypeId, $months]);

            // 4. Record fee in payments if not already recorded
            $insPay = $pdo->prepare(
                "INSERT INTO payments (user_id, amount, payment_method, transaction_ref, payment_type, payment_date)
                 VALUES (?, ?, ?, ?, 'membership', NOW())"
            );
            $insPay->execute([$targetUserId, $fee, $request['payment_method'], $request['transaction_ref']]);

            log_activity($pdo, $uid, 'MEMBERSHIP_APPROVE', "Approved request #$reqId for user_id=$targetUserId ($months month(s), $fee BDT)");
            $pdo->commit();

            $msg = "✓ Premium Membership application #$reqId approved! User #$targetUserId upgraded to Premium for $months month(s).";
            $activeTab = 'premium_requests';
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Error approving membership: ' . $ex->getMessage();
            $activeTab = 'premium_requests';
        }
    }

    // Action 5: Reject Premium Membership Request (Requirement 3)
    elseif ($action === 'reject_premium_request') {
        $reqId = (int) ($_POST['request_id'] ?? 0);
        $adminNotes = trim($_POST['admin_notes'] ?? 'Application rejected by administration.');

        $updReq = $pdo->prepare("UPDATE membership_requests SET status = 'rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ? AND status = 'pending'");
        $updReq->execute([$adminNotes, $uid, $reqId]);

        log_activity($pdo, $uid, 'MEMBERSHIP_REJECT', "Rejected request #$reqId with note: $adminNotes");
        $msg = "✓ Application #$reqId rejected.";
        $activeTab = 'premium_requests';
    }

    // Action 6: Update Global Library Settings (Requirement 5)
    elseif ($action === 'update_settings') {
        $settings = $_POST['settings'] ?? [];
        if (is_array($settings)) {
            $stmt = $pdo->prepare('INSERT INTO library_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($settings as $key => $val) {
                $stmt->execute([trim((string)$key), trim((string)$val)]);
            }
            log_activity($pdo, $uid, 'UPDATE_SETTINGS', 'Updated library global configuration');
            $msg = '✓ Library settings updated successfully.';
            $activeTab = 'settings';
        }
    }

    // Action 7: Save / Add Membership Tier
    elseif ($action === 'save_membership_type') {
        $typeId       = (int) ($_POST['membership_type_id'] ?? 0);
        $typeName     = trim($_POST['type_name'] ?? '');
        $maxBooks     = (int) ($_POST['max_books'] ?? 3);
        $loanDays     = (int) ($_POST['loan_days'] ?? 14);
        $renewalLimit = (int) ($_POST['renewal_limit'] ?? 1);
        $monthlyFee   = (float) ($_POST['monthly_fee'] ?? 0.00);
        $desc         = trim($_POST['description'] ?? '');

        if (!$typeName) {
            $err = 'Membership type name is required.';
            $activeTab = 'memberships';
        } else {
            if ($typeId > 0) {
                $stmt = $pdo->prepare('UPDATE membership_types SET type_name=?, max_books=?, loan_days=?, renewal_limit=?, monthly_fee=?, description=? WHERE membership_type_id=?');
                $stmt->execute([$typeName, $maxBooks, $loanDays, $renewalLimit, $monthlyFee, $desc, $typeId]);
                $msg = "✓ Membership tier '$typeName' updated.";
            } else {
                $stmt = $pdo->prepare('INSERT INTO membership_types (type_name, max_books, loan_days, renewal_limit, monthly_fee, description) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$typeName, $maxBooks, $loanDays, $renewalLimit, $monthlyFee, $desc]);
                $msg = "✓ New membership tier '$typeName' added.";
            }
            log_activity($pdo, $uid, 'SAVE_MEMBERSHIP_TYPE', "type_name=$typeName");
            $activeTab = 'memberships';
        }
    }
}

// -------------------------------------------------------------
// Data Queries for Admin Panel
// -------------------------------------------------------------

// System KPIs
$systemStats = [
    'total_users'     => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'admins'          => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
    'librarians'      => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'librarian'")->fetchColumn(),
    'members'         => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
    'suspended'       => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn(),
    'total_titles'    => (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn(),
    'total_copies'    => (int) $pdo->query('SELECT COUNT(*) FROM book_copies')->fetchColumn(),
    'active_borrowed' => (int) $pdo->query('SELECT COUNT(*) FROM borrowings WHERE return_date IS NULL')->fetchColumn(),
    'overdue_count'   => (int) $pdo->query('SELECT COUNT(*) FROM borrowings WHERE return_date IS NULL AND due_date < CURDATE()')->fetchColumn(),
    'pending_fines'   => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'pending'")->fetchColumn(),
    'paid_fines'      => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'paid'")->fetchColumn(),
    'waived_fines'    => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'waived'")->fetchColumn(),
    'pending_prem_cnt'=> (int) $pdo->query("SELECT COUNT(*) FROM membership_requests WHERE status = 'pending'")->fetchColumn(),
];

// Premium Requests List (Requirement 3)
$premRequests = $pdo->query(
    "SELECT mr.*, u.full_name, u.email, mt.type_name, u_rev.full_name AS reviewer_name
     FROM membership_requests mr
     JOIN users u ON u.user_id = mr.user_id
     JOIN membership_types mt ON mt.membership_type_id = mr.membership_type_id
     LEFT JOIN users u_rev ON u_rev.user_id = mr.reviewed_by
     ORDER BY mr.status = 'pending' DESC, mr.created_at DESC"
)->fetchAll();

// Librarian Roster & Operational Activity Metrics (Requirement 1)
$librarianList = $pdo->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM borrowings bw WHERE bw.issued_by = u.user_id) AS issued_count,
            (SELECT COUNT(*) FROM activity_logs l WHERE l.user_id = u.user_id AND l.action IN ('LIBRARIAN_CHECKIN', 'RETURN', 'RETURN_BARCODE')) AS checkin_count,
            (SELECT COUNT(*) FROM activity_logs l WHERE l.user_id = u.user_id AND l.action = 'FINE_WAIVE') AS waived_count,
            (SELECT COUNT(*) FROM activity_logs l WHERE l.user_id = u.user_id AND l.action = 'MANUAL_FINE_ASSESS') AS manual_fine_count
     FROM users u
     WHERE u.role = 'librarian'
     ORDER BY u.created_at ASC"
)->fetchAll();

// Filtered Librarian Activity Logs (Requirement 1)
$selectedLibrarianId = (int) ($_GET['lib_filter_id'] ?? 0);
$libLogSql = "SELECT l.*, u.full_name, u.email FROM activity_logs l
              JOIN users u ON u.user_id = l.user_id
              WHERE u.role = 'librarian'";
if ($selectedLibrarianId > 0) {
    $libLogSql .= " AND l.user_id = $selectedLibrarianId";
}
$libLogSql .= " ORDER BY l.created_at DESC LIMIT 60";
$librarianLogs = $pdo->query($libLogSql)->fetchAll();

// Overdue Loans
$overdue = $pdo->query(
    'SELECT bw.*, u.full_name, u.email, b.title, c.barcode,
            DATEDIFF(CURDATE(), bw.due_date) AS days_late
     FROM borrowings bw
     JOIN users u ON u.user_id = bw.user_id
     JOIN book_copies c ON c.copy_id = bw.copy_id
     JOIN books b ON b.book_id = c.book_id
     WHERE bw.return_date IS NULL AND bw.due_date < CURDATE()
     ORDER BY days_late DESC LIMIT 30'
)->fetchAll();

// Popular Books
$popular = $pdo->query(
    'SELECT b.title, COUNT(*) AS times_borrowed
     FROM borrowings bw
     JOIN book_copies c ON c.copy_id = bw.copy_id
     JOIN books b ON b.book_id = c.book_id
     GROUP BY b.book_id, b.title ORDER BY times_borrowed DESC LIMIT 10'
)->fetchAll();

// User List (with search/filter)
$userSearch = trim($_GET['user_q'] ?? '');
$userRoleFilter = $_GET['user_role'] ?? '';
$userSql = 'SELECT u.*,
            (SELECT mt.type_name FROM memberships m JOIN membership_types mt ON mt.membership_type_id = m.membership_type_id WHERE m.user_id = u.user_id AND m.status = "active" LIMIT 1) AS mtype
            FROM users u';
$uConds = []; $uParams = [];
if ($userSearch) {
    $uConds[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
    $uParams[] = "%$userSearch%";
    $uParams[] = "%$userSearch%";
}
if ($userRoleFilter && in_array($userRoleFilter, ['admin', 'librarian', 'member'], true)) {
    $uConds[] = 'u.role = ?';
    $uParams[] = $userRoleFilter;
}
if ($uConds) {
    $userSql .= ' WHERE ' . implode(' AND ', $uConds);
}
$userSql .= ' ORDER BY u.created_at DESC LIMIT 100';
$uStmt = $pdo->prepare($userSql);
$uStmt->execute($uParams);
$userList = $uStmt->fetchAll();

// System Settings
$settingsList = $pdo->query('SELECT * FROM library_settings ORDER BY setting_key ASC')->fetchAll();

// Membership Types List
$membershipTypes = $pdo->query('SELECT * FROM membership_types ORDER BY membership_type_id ASC')->fetchAll();

// System Activity Logs
$logSearch = trim($_GET['log_q'] ?? '');
$logSql = 'SELECT l.*, u.full_name, u.email FROM activity_logs l LEFT JOIN users u ON u.user_id = l.user_id';
if ($logSearch) {
    $logSql .= ' WHERE l.action LIKE ' . $pdo->quote("%$logSearch%") . ' OR l.details LIKE ' . $pdo->quote("%$logSearch%") . ' OR u.full_name LIKE ' . $pdo->quote("%$logSearch%");
}
$logSql .= ' ORDER BY l.created_at DESC LIMIT 60';
$logs = $pdo->query($logSql)->fetchAll();

$page = 'Admin Control Panel';
include 'includes/header.php';
?>

<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
    <div>
      <h2>⚙️ Admin Control Panel &amp; System Oversight</h2>
      <p class="sub" style="margin-bottom:0">Master administration: user governance, librarian oversight, premium approvals, and global configuration.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="librarian_dashboard.php" class="btn-sm btn-secondary" style="text-decoration:none;">📖 Preview Librarian Desk</a>
      <a href="dashboard.php?view=user" class="btn-sm btn-outline" style="text-decoration:none;">👤 Preview User View</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <!-- Admin Navigation Tabs -->
  <div class="nav-tabs" style="margin-bottom:22px;">
    <a href="admin_dashboard.php?tab=overview" class="<?= $activeTab === 'overview' ? 'active' : '' ?>">
      📊 Overview &amp; Analytics
    </a>
    <a href="admin_dashboard.php?tab=librarians" class="<?= $activeTab === 'librarians' ? 'active' : '' ?>">
      📖 Librarian Activities &amp; Oversight <span class="tab-badge"><?= $systemStats['librarians'] ?></span>
    </a>
    <a href="admin_dashboard.php?tab=premium_requests" class="<?= $activeTab === 'premium_requests' ? 'active' : '' ?>">
      ⭐ Premium Applications <?= $systemStats['pending_prem_cnt'] > 0 ? '<span class="tab-badge" style="background:#f59e0b;color:#0f172a;">' . $systemStats['pending_prem_cnt'] . '</span>' : '' ?>
    </a>
    <a href="admin_dashboard.php?tab=users" class="<?= $activeTab === 'users' ? 'active' : '' ?>">
      👥 User Directory <span class="tab-badge"><?= $systemStats['total_users'] ?></span>
    </a>
    <a href="admin_dashboard.php?tab=memberships" class="<?= $activeTab === 'memberships' ? 'active' : '' ?>">
      💳 Membership Tiers
    </a>
    <a href="admin_dashboard.php?tab=settings" class="<?= $activeTab === 'settings' ? 'active' : '' ?>">
      ⚙️ Library Settings
    </a>
    <a href="admin_dashboard.php?tab=logs" class="<?= $activeTab === 'logs' ? 'active' : '' ?>">
      📜 System Audit Logs
    </a>
  </div>

  <!-- TAB 1: OVERVIEW & REPORTS -->
  <?php if ($activeTab === 'overview'): ?>
    <h3 style="color:var(--navy);font-size:1.05rem;margin-bottom:10px;">Executive System Metrics</h3>
    <div class="stat-row">
      <div class="stat"><b><?= $systemStats['total_users'] ?></b><span>Total Users</span></div>
      <div class="stat"><b><?= $systemStats['admins'] ?> / <?= $systemStats['librarians'] ?> / <?= $systemStats['members'] ?></b><span>Adm / Lib / Mem</span></div>
      <div class="stat"><b><?= $systemStats['total_titles'] ?></b><span>Book Titles</span></div>
      <div class="stat"><b><?= $systemStats['total_copies'] ?></b><span>Total Copies</span></div>
      <div class="stat"><b><?= $systemStats['active_borrowed'] ?></b><span>Active Borrowed</span></div>
      <div class="stat"><b style="color:var(--danger);"><?= $systemStats['overdue_count'] ?></b><span>Overdue Items</span></div>
    </div>

    <!-- Financial Stats Row -->
    <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:12px;padding:20px;margin-bottom:26px;">
      <h4 style="color:var(--navy);margin-bottom:10px;">💰 Fine &amp; Revenue Overview</h4>
      <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:.92rem;">
        <div>Pending Fines: <strong style="color:var(--danger);"><?= number_format($systemStats['pending_fines'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?></strong></div>
        <div>Collected Fines: <strong style="color:var(--ok);"><?= number_format($systemStats['paid_fines'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?></strong></div>
        <div>Waived Fines: <strong><?= number_format($systemStats['waived_fines'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?></strong></div>
      </div>
    </div>

    <!-- Overdue Loans Table -->
    <h3 style="color:var(--navy);font-size:1.02rem;margin-top:20px;">⚠ Overdue Loans Overview</h3>
    <table>
      <thead>
        <tr><th>Member</th><th>Book Title</th><th>Barcode</th><th>Due Date</th><th>Days Late</th><th>Est. Fine</th></tr>
      </thead>
      <tbody>
        <?php $fpd = (float) get_setting($pdo, 'fine_per_day', '5.00'); ?>
        <?php foreach ($overdue as $o): ?>
          <tr>
            <td><strong><?= e($o['full_name']) ?></strong><br><small><?= e($o['email']) ?></small></td>
            <td><strong><?= e($o['title']) ?></strong></td>
            <td><code><?= e($o['barcode']) ?></code></td>
            <td class="overdue"><?= e($o['due_date']) ?></td>
            <td class="overdue"><?= (int) $o['days_late'] ?> day(s)</td>
            <td><?= number_format($o['days_late'] * $fpd, 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($overdue)): ?><tr><td colspan="6">No overdue loans currently. 🎉</td></tr><?php endif; ?>
      </tbody>
    </table>

    <!-- Popular Books Table -->
    <h3 style="color:var(--navy);font-size:1.02rem;margin-top:28px;">🔥 Most Borrowed Books</h3>
    <table>
      <thead>
        <tr><th>#</th><th>Book Title</th><th>Times Borrowed</th></tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($popular as $p): ?>
          <tr><td><?= $i++ ?></td><td><strong><?= e($p['title']) ?></strong></td><td><?= (int) $p['times_borrowed'] ?> times</td></tr>
        <?php endforeach; ?>
        <?php if (empty($popular)): ?><tr><td colspan="3">No borrowing data recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- TAB 2: LIBRARIAN ACTIVITIES & OVERSIGHT (REQUIREMENT 1) -->
  <?php if ($activeTab === 'librarians'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
      <div>
        <h3 style="color:var(--navy);font-size:1.1rem;">📖 Librarian Oversight &amp; Activity Monitoring</h3>
        <p class="sub" style="margin-bottom:0">Review operational activity, circulation transactions, and fine waivers executed by staff librarians.</p>
      </div>
    </div>

    <!-- Librarian Roster & Operational Performance Table -->
    <table>
      <thead>
        <tr>
          <th>Librarian Name &amp; Email</th>
          <th>Account Status</th>
          <th>Books Issued</th>
          <th>Returns Processed</th>
          <th>Fines Waived</th>
          <th>Manual Fines</th>
          <th>Admin Control</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($librarianList as $lib): ?>
          <tr>
            <td>
              <strong><?= e($lib['full_name']) ?></strong><br>
              <small style="color:var(--muted);"><?= e($lib['email']) ?></small>
            </td>
            <td>
              <span class="tag <?= $lib['status'] === 'active' ? 'ok' : 'bad' ?>">
                <?= strtoupper(e($lib['status'])) ?>
              </span>
            </td>
            <td><strong><?= (int)$lib['issued_count'] ?></strong> loans</td>
            <td><strong><?= (int)$lib['checkin_count'] ?></strong> returns</td>
            <td><?= (int)$lib['waived_count'] ?> fine(s) waived</td>
            <td><?= (int)$lib['manual_fine_count'] ?> assessed</td>
            <td class="actions">
              <!-- Toggle Status Form -->
              <form method="post" action="admin_dashboard.php?tab=librarians" style="display:inline-block;">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="target_user_id" value="<?= (int) $lib['user_id'] ?>">
                <input type="hidden" name="redirect_tab" value="librarians">
                <button type="submit" class="btn-sm <?= $lib['status'] === 'active' ? 'btn-danger' : 'btn-success' ?>"
                        onclick="return confirm('Change status for librarian <?= e($lib['full_name']) ?>?')">
                  <?= $lib['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                </button>
              </form>
              <a href="admin_dashboard.php?tab=librarians&lib_filter_id=<?= (int)$lib['user_id'] ?>" class="btn-sm btn-secondary" style="text-decoration:none;">
                Filter Logs
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($librarianList)): ?>
          <tr><td colspan="7">No librarian accounts found. Create one in the User Management tab!</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Librarian Activity Audit Log -->
    <div style="margin-top:30px;">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
        <h4 style="color:var(--navy);font-size:1.02rem;">
          📜 Librarian Activity Stream
          <?= $selectedLibrarianId > 0 ? " (Filtered by Librarian #$selectedLibrarianId)" : '' ?>
        </h4>
        <?php if ($selectedLibrarianId > 0): ?>
          <a href="admin_dashboard.php?tab=librarians" class="btn-sm btn-secondary" style="text-decoration:none;">Show All Librarians</a>
        <?php endif; ?>
      </div>

      <table>
        <thead>
          <tr>
            <th>Time</th>
            <th>Librarian</th>
            <th>Action</th>
            <th>Details &amp; Audit Trail</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($librarianLogs as $ll): ?>
            <tr>
              <td><small><?= e($ll['created_at']) ?></small></td>
              <td><strong><?= e($ll['full_name']) ?></strong></td>
              <td><span class="tag info"><?= e($ll['action']) ?></span></td>
              <td><code><?= e($ll['details']) ?></code></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($librarianLogs)): ?>
            <tr><td colspan="4">No activity logs recorded for staff librarians yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- TAB 3: ⭐ PREMIUM MEMBERSHIP APPLICATIONS (REQUIREMENT 3) -->
  <?php if ($activeTab === 'premium_requests'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
      <div>
        <h3 style="color:var(--navy);font-size:1.1rem;">⭐ Member Premium Applications &amp; Review Desk</h3>
        <p class="sub" style="margin-bottom:0">Review user-submitted upgrade applications, verify dummy/sandbox payments, and approve or reject membership tiers.</p>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>App #</th>
          <th>Applicant Name &amp; Email</th>
          <th>Duration &amp; Fee</th>
          <th>Payment Details</th>
          <th>Applicant Note</th>
          <th>Status</th>
          <th>Admin Decision</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($premRequests as $pr): 
          $pst = $pr['status'];
          $pBadge = match($pst) {
              'approved' => 'ok',
              'pending'  => 'warn',
              'rejected' => 'bad',
              default    => 'info'
          };
        ?>
          <tr>
            <td><code>#REQ-<?= (int)$pr['request_id'] ?></code></td>
            <td>
              <strong><?= e($pr['full_name']) ?></strong><br>
              <small style="color:var(--muted);"><?= e($pr['email']) ?></small>
            </td>
            <td>
              <strong><?= (int)$pr['duration_months'] ?> Month(s)</strong><br>
              <small style="font-weight:700;color:var(--ok);"><?= number_format((float)$pr['fee'], 2) ?> BDT</small>
            </td>
            <td>
              <?= e($pr['payment_method']) ?><br>
              <small>Txn: <code><?= e($pr['transaction_ref']) ?></code></small>
            </td>
            <td><small><?= e($pr['notes'] ?: '–') ?></small></td>
            <td>
              <span class="tag <?= $pBadge ?>">
                <?= $pst === 'approved' ? '✓ Approved' : ($pst === 'pending' ? '⏳ Pending Review' : '✗ Rejected') ?>
              </span>
              <?php if ($pr['reviewed_at']): ?>
                <br><small style="color:var(--muted);font-size:.72rem;">Reviewed by <?= e($pr['reviewer_name'] ?: 'Admin') ?></small>
              <?php endif; ?>
            </td>
            <td class="actions">
              <?php if ($pst === 'pending'): ?>
                <!-- Approve Form -->
                <form method="post" action="admin_dashboard.php?tab=premium_requests" style="display:inline;">
                  <input type="hidden" name="action" value="approve_premium_request">
                  <input type="hidden" name="request_id" value="<?= (int) $pr['request_id'] ?>">
                  <button type="submit" class="btn-sm btn-success" onclick="return confirm('Approve Premium Membership for <?= e($pr['full_name']) ?> for <?= (int)$pr['duration_months'] ?> month(s)?')">
                    ✓ Approve
                  </button>
                </form>

                <!-- Reject Button -->
                <button type="button" class="btn-sm btn-danger" onclick="openPremRejectModal(<?= (int)$pr['request_id'] ?>, '<?= e(addslashes($pr['full_name'])) ?>')">
                  ✗ Reject
                </button>
              <?php else: ?>
                <small style="color:var(--muted);"><?= $pst === 'approved' ? 'Active Membership' : e($pr['admin_notes']) ?></small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($premRequests)): ?>
          <tr><td colspan="7">No Premium Membership requests have been submitted yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- TAB 4: USER MANAGEMENT -->
  <?php if ($activeTab === 'users'): ?>
    <!-- Create User Form -->
    <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:12px;padding:24px;margin-bottom:26px;">
      <h3 style="color:var(--navy);font-size:1.05rem;margin-bottom:12px;">➕ Create New User / Staff Account</h3>
      <form method="post" action="admin_dashboard.php?tab=users">
        <input type="hidden" name="action" value="create_user">
        <div class="form-row">
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" placeholder="e.g. John Doe" required>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="e.g. john@example.com" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Initial Password</label>
            <input type="password" name="password" placeholder="Min 8 characters" required>
          </div>
          <div class="form-group">
            <label>Assign System Role</label>
            <select name="role" required>
              <option value="member">Member</option>
              <option value="librarian">Librarian</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <button type="submit" class="btn-sm" style="margin-top:14px;padding:9px 24px;">Create Account</button>
      </form>
    </div>

    <!-- Search & Filter Bar -->
    <div class="toolbar">
      <form method="get" action="admin_dashboard.php">
        <input type="hidden" name="tab" value="users">
        <input type="text" name="user_q" value="<?= e($userSearch) ?>" placeholder="Search name or email..." style="min-width:220px;">
        <select name="user_role">
          <option value="">All Roles</option>
          <option value="admin" <?= $userRoleFilter === 'admin' ? 'selected' : '' ?>>Admins</option>
          <option value="librarian" <?= $userRoleFilter === 'librarian' ? 'selected' : '' ?>>Librarians</option>
          <option value="member" <?= $userRoleFilter === 'member' ? 'selected' : '' ?>>Members</option>
        </select>
        <button type="submit" class="btn-sm">Filter</button>
        <?php if ($userSearch || $userRoleFilter): ?>
          <a href="admin_dashboard.php?tab=users" class="btn-sm btn-secondary" style="text-decoration:none;">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- User Directory Table -->
    <table>
      <thead>
        <tr><th>Name &amp; Email</th><th>Role</th><th>Membership</th><th>Status</th><th>Registered</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($userList as $u): ?>
          <tr>
            <td>
              <strong><?= e($u['full_name']) ?></strong>
              <?php if ((int)$u['user_id'] === $uid): ?><span class="tag info">You</span><?php endif; ?><br>
              <small style="color:var(--muted);"><?= e($u['email']) ?></small>
            </td>
            <td>
              <span class="tag <?= $u['role'] === 'admin' ? 'purple' : ($u['role'] === 'librarian' ? 'gold' : 'info') ?>">
                <?= e(strtoupper($u['role'])) ?>
              </span>
            </td>
            <td><?= e($u['mtype'] ?: 'N/A') ?></td>
            <td>
              <span class="tag <?= $u['status'] === 'active' ? 'ok' : 'bad' ?>">
                <?= e(strtoupper($u['status'])) ?>
              </span>
            </td>
            <td><small><?= e(substr($u['created_at'], 0, 10)) ?></small></td>
            <td class="actions">
              <?php if ((int)$u['user_id'] !== $uid): ?>
                <!-- Update Role Form -->
                <form method="post" action="admin_dashboard.php?tab=users" style="display:inline-block;">
                  <input type="hidden" name="action" value="update_role">
                  <input type="hidden" name="target_user_id" value="<?= (int) $u['user_id'] ?>">
                  <select name="role" onchange="this.form.submit()" style="padding:4px 8px;font-size:.78rem;width:auto;margin:0;">
                    <option value="member" <?= $u['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                    <option value="librarian" <?= $u['role'] === 'librarian' ? 'selected' : '' ?>>Librarian</option>
                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                  </select>
                </form>

                <!-- Toggle Status Form -->
                <form method="post" action="admin_dashboard.php?tab=users" style="display:inline-block;">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="target_user_id" value="<?= (int) $u['user_id'] ?>">
                  <input type="hidden" name="redirect_tab" value="users">
                  <button type="submit" class="btn-sm <?= $u['status'] === 'active' ? 'btn-danger' : 'btn-success' ?>"
                          onclick="return confirm('Change account status for <?= e($u['full_name']) ?>?')">
                    <?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                  </button>
                </form>
              <?php else: ?>
                <small style="color:var(--muted);">Current Admin</small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- TAB 5: MEMBERSHIP TIERS -->
  <?php if ($activeTab === 'memberships'): ?>
    <h3 style="color:var(--navy);font-size:1.05rem;margin-bottom:12px;">Configure Membership Tiers</h3>
    
    <!-- Add/Edit Tier Form -->
    <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:12px;padding:24px;margin-bottom:24px;">
      <h4 style="color:var(--navy);margin-bottom:10px;">➕ Add / Update Membership Tier</h4>
      <form method="post" action="admin_dashboard.php?tab=memberships">
        <input type="hidden" name="action" value="save_membership_type">
        <input type="hidden" name="membership_type_id" value="0">
        
        <div class="form-row">
          <div class="form-group">
            <label>Tier Name</label>
            <input type="text" name="type_name" placeholder="e.g. VIP Scholar" required>
          </div>
          <div class="form-group">
            <label>Max Books Allowed</label>
            <input type="number" name="max_books" value="3" min="1" max="20" required>
          </div>
          <div class="form-group">
            <label>Loan Days</label>
            <input type="number" name="loan_days" value="14" min="1" max="90" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Renewal Limit</label>
            <input type="number" name="renewal_limit" value="1" min="0" max="10" required>
          </div>
          <div class="form-group">
            <label>Monthly Fee (BDT)</label>
            <input type="number" step="0.01" name="monthly_fee" value="0.00" required>
          </div>
        </div>

        <div class="form-group">
          <label>Description of Privileges</label>
          <input type="text" name="description" placeholder="Brief description of tier benefits">
        </div>

        <button type="submit" class="btn-sm" style="margin-top:14px;padding:9px 24px;">Save Tier</button>
      </form>
    </div>

    <!-- Tiers Table -->
    <table>
      <thead>
        <tr><th>ID</th><th>Type Name</th><th>Max Books</th><th>Loan Days</th><th>Renewal Limit</th><th>Monthly Fee</th><th>Description</th></tr>
      </thead>
      <tbody>
        <?php foreach ($membershipTypes as $mt): ?>
          <tr>
            <td><?= (int)$mt['membership_type_id'] ?></td>
            <td><strong><?= e($mt['type_name']) ?></strong></td>
            <td><?= (int)$mt['max_books'] ?> books</td>
            <td><?= (int)$mt['loan_days'] ?> days</td>
            <td><?= (int)$mt['renewal_limit'] ?> time(s)</td>
            <td><?= number_format((float)$mt['monthly_fee'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?></td>
            <td><?= e($mt['description'] ?: '–') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- TAB 6: LIBRARY SETTINGS -->
  <?php if ($activeTab === 'settings'): ?>
    <h3 style="color:var(--navy);font-size:1.05rem;margin-bottom:12px;">⚙️ Master Global Library Settings</h3>
    <form method="post" action="admin_dashboard.php?tab=settings">
      <input type="hidden" name="action" value="update_settings">
      
      <table>
        <thead>
          <tr><th>Setting Key</th><th>Setting Value</th><th>Rule Description</th></tr>
        </thead>
        <tbody>
          <?php foreach ($settingsList as $st): ?>
            <tr>
              <td><code><?= e($st['setting_key']) ?></code></td>
              <td>
                <input type="text" name="settings[<?= e($st['setting_key']) ?>]" value="<?= e($st['setting_value']) ?>" style="padding:7px 12px;margin:0;">
              </td>
              <td><small style="color:#475569;"><?= e($st['description'] ?: 'System rule setting') ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <button type="submit" class="btn-sm" style="margin-top:16px;padding:10px 24px;">Save Configuration</button>
    </form>
  <?php endif; ?>

  <!-- TAB 7: SYSTEM AUDIT LOGS -->
  <?php if ($activeTab === 'logs'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
      <h3 style="color:var(--navy);font-size:1.05rem;">📜 Real-Time Audit &amp; System Logs</h3>
      <form method="get" action="admin_dashboard.php" style="display:flex;gap:8px;">
        <input type="hidden" name="tab" value="logs">
        <input type="text" name="log_q" value="<?= e($logSearch) ?>" placeholder="Filter logs by action/user..." style="padding:6px 10px;margin:0;">
        <button type="submit" class="btn-sm btn-secondary" style="margin:0;">Search Logs</button>
      </form>
    </div>

    <table>
      <thead>
        <tr><th>Time</th><th>User / Staff</th><th>Action</th><th>Details</th></tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><small><?= e($l['created_at']) ?></small></td>
            <td><?= e($l['full_name'] ? $l['full_name'] . ' (' . $l['email'] . ')' : 'System') ?></td>
            <td><span class="tag info"><?= e($l['action']) ?></span></td>
            <td><code><?= e($l['details']) ?></code></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="4">No logs matched your criteria.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div>

<!-- Modal Dialog: Reject Premium Request -->
<div id="prem-reject-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;max-width:440px;width:90%;padding:26px;box-shadow:var(--shadow-lg);">
    <h3 style="color:var(--navy);font-size:1.1rem;margin-bottom:6px;">✗ Reject Premium Application</h3>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px;" id="prem-reject-info">Provide reason for rejection.</p>
    
    <form method="POST" action="admin_dashboard.php?tab=premium_requests">
      <input type="hidden" name="action" value="reject_premium_request">
      <input type="hidden" name="request_id" id="prem-reject-id">

      <label>Feedback Reason for Member *</label>
      <select name="admin_notes" required>
        <option value="Transaction reference could not be verified.">Transaction reference could not be verified</option>
        <option value="Duplicate or conflicting application.">Duplicate or conflicting application</option>
        <option value="Outstanding fines must be cleared first.">Outstanding fines must be cleared first</option>
        <option value="Application rejected as per library policy.">Application rejected as per library policy</option>
      </select>

      <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn-sm btn-secondary" onclick="closePremRejectModal()">Cancel</button>
        <button type="submit" class="btn-sm btn-danger" style="width:auto;">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPremRejectModal(reqId, applicantName) {
  document.getElementById('prem-reject-id').value = reqId;
  document.getElementById('prem-reject-info').textContent = 'Rejecting application for: ' + applicantName;
  document.getElementById('prem-reject-modal').style.display = 'flex';
}

function closePremRejectModal() {
  document.getElementById('prem-reject-modal').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>