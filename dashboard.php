<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$uid  = (int) $_SESSION['user_id'];
$role = current_role();
$viewMode = $_GET['view'] ?? '';

// If a librarian lands on dashboard without requesting user view, take them to their dedicated Librarian Desk
if ($role === 'librarian' && $viewMode !== 'user') {
    header('Location: librarian_dashboard.php');
    exit;
}

// Check if staff is previewing user view or if actual member
$showUserView = ($role === 'member') || ($viewMode === 'user');

if (!$showUserView && $role === 'admin') {
    // Admin executive overview
    $stat = [
        'books'          => (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn(),
        'copies'         => (int) $pdo->query('SELECT COUNT(*) FROM book_copies')->fetchColumn(),
        'available'      => (int) $pdo->query("SELECT COUNT(*) FROM book_copies WHERE status = 'available'")->fetchColumn(),
        'members'        => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
        'librarians'     => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'librarian'")->fetchColumn(),
        'active'         => (int) $pdo->query('SELECT COUNT(*) FROM borrowings WHERE return_date IS NULL')->fetchColumn(),
        'overdue'        => (int) $pdo->query('SELECT COUNT(*) FROM borrowings WHERE return_date IS NULL AND due_date < CURDATE()')->fetchColumn(),
        'pendingRes'     => (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn(),
        'pendingFine'    => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'pending'")->fetchColumn(),
        'pendingPremReq' => (int) $pdo->query("SELECT COUNT(*) FROM membership_requests WHERE status = 'pending'")->fetchColumn(),
    ];
} else {
    // Member statistics
    $membership = user_membership($pdo, $uid);
    $myActive   = active_borrow_count($pdo, $uid);
    $myFines    = (float) $pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM fines f JOIN borrowings bw ON bw.borrowing_id = f.borrowing_id
         WHERE bw.user_id = $uid AND f.status = 'pending'")->fetchColumn();
    $myReservations = (int) $pdo->query(
        "SELECT COUNT(*) FROM reservations WHERE user_id = $uid AND status = 'pending'")->fetchColumn();
    $myConnections = (int) $pdo->query(
        "SELECT COUNT(*) FROM community_connections WHERE (sender_id = $uid OR receiver_id = $uid) AND status = 'accepted'")->fetchColumn();
    $myExchangeBooks = (int) $pdo->query(
        "SELECT COUNT(*) FROM member_books WHERE owner_id = $uid AND status = 'available'")->fetchColumn();

    // Fetch user's active borrowings
    $myLoans = $pdo->prepare(
        "SELECT bw.*, b.title, c.barcode,
                (SELECT COUNT(*) FROM fines fn WHERE fn.borrowing_id = bw.borrowing_id AND fn.status = 'pending') AS has_fine
         FROM borrowings bw
         JOIN book_copies c ON c.copy_id = bw.copy_id
         JOIN books b ON b.book_id = c.book_id
         WHERE bw.user_id = ? AND bw.return_date IS NULL
         ORDER BY bw.due_date ASC"
    );
    $myLoans->execute([$uid]);
    $activeLoans = $myLoans->fetchAll();

    // Check user's pending premium application
    $premReqStmt = $pdo->prepare("SELECT * FROM membership_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $premReqStmt->execute([$uid]);
    $latestPremReq = $premReqStmt->fetch();
}

$page = 'Dashboard';
include 'includes/header.php';
?>

<div class="card wide">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
    <div>
      <h2>Welcome, <?= e($_SESSION['full_name']) ?> 👋</h2>
      <p class="sub" style="margin-bottom:0">
        Signed in as <strong><?= e(strtoupper($role)) ?></strong>
        <?php if ($showUserView && isset($membership) && $membership): ?>
          &nbsp;•&nbsp; Tier: <span class="tag <?= $membership['type_name'] === 'Premium' ? 'gold' : 'info' ?>">
            <?= $membership['type_name'] === 'Premium' ? '⭐ ' : '' ?><?= e($membership['type_name']) ?> Member
          </span>
          (Max <?= (int) $membership['max_books'] ?> books, <?= (int) $membership['loan_days'] ?> days/loan)
        <?php endif; ?>
      </p>
    </div>

    <!-- View Switcher Quick Actions for Staff -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($role === 'admin'): ?>
        <a href="admin_dashboard.php" class="btn-sm" style="text-decoration:none;">⚙️ Admin Control Panel</a>
        <a href="librarian_dashboard.php" class="btn-sm btn-secondary" style="text-decoration:none;">📖 Librarian View</a>
        <?php if ($viewMode !== 'user'): ?>
          <a href="dashboard.php?view=user" class="btn-sm btn-outline" style="text-decoration:none;">👤 Preview User View</a>
        <?php else: ?>
          <a href="dashboard.php" class="btn-sm btn-secondary" style="text-decoration:none;">🔙 Exit User Preview</a>
        <?php endif; ?>
      <?php elseif ($role === 'librarian'): ?>
        <a href="librarian_dashboard.php" class="btn-sm" style="text-decoration:none;">📖 Go to Librarian Desk</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$showUserView && $role === 'admin'): ?>
    <!-- ADMIN EXECUTIVE HUB -->
    <div class="stat-row">
      <div class="stat"><b><?= $stat['books'] ?></b><span>Catalog Titles</span></div>
      <div class="stat"><b><?= $stat['copies'] ?></b><span>Total Copies</span></div>
      <div class="stat"><b><?= $stat['available'] ?></b><span>Available</span></div>
      <div class="stat"><b><?= $stat['members'] ?></b><span>Members</span></div>
      <div class="stat"><b><?= $stat['librarians'] ?></b><span>Librarians</span></div>
      <div class="stat"><b><?= $stat['active'] ?></b><span>Active Loans</span></div>
      <div class="stat"><b style="color:var(--danger)"><?= $stat['overdue'] ?></b><span>Overdue</span></div>
      <div class="stat"><b><?= $stat['pendingRes'] ?></b><span>Reservations</span></div>
      <div class="stat"><b style="color:var(--ok)"><?= number_format($stat['pendingFine'], 2) ?></b><span>Pending Fines</span></div>
      <div class="stat"><b style="color:var(--warn)"><?= $stat['pendingPremReq'] ?></b><span>Premium Apps</span></div>
    </div>

    <div class="toolbar" style="margin-top:20px;">
      <a class="btn-sm" style="text-decoration:none" href="admin_dashboard.php">⚙️ Admin Control Panel</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="librarian_dashboard.php">📖 Librarian Desk View</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="borrow.php">➕ Issue Book</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="borrowings.php?f=overdue">⚠ Overdue Loans</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="books.php">📚 Manage Catalog</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="fines.php">💰 Fine Management</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="community.php">🤝 Community Hub</a>
    </div>

  <?php else: ?>
    <!-- USER / MEMBER DASHBOARD -->
    <?php if (isset($membership) && $membership && $membership['type_name'] !== 'Premium'): ?>
      <!-- Upgrade to Premium Banner -->
      <div class="premium-hero-box" style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
          <div>
            <span class="premium-badge-float">⭐ Level Up Your Reading</span>
            <h3 style="color:#fff;font-size:1.35rem;margin-bottom:6px;">Upgrade to Premium Membership</h3>
            <p style="color:#cbd5e1;font-size:.9rem;max-width:620px;line-height:1.5;">
              Enjoy double the book limit (6 books at once), extended 30-day loans, up to 3 renewals, and priority reservation queue.
            </p>
          </div>
          <div>
            <?php if ($latestPremReq && $latestPremReq['status'] === 'pending'): ?>
              <span class="tag warn" style="font-size:.85rem;padding:8px 14px;">⏳ Application Pending Review</span>
            <?php else: ?>
              <a href="membership.php" class="btn" style="background:#f59e0b;color:#0f172a;font-weight:700;padding:10px 22px;border-radius:8px;box-shadow:0 4px 14px rgba(245,158,11,0.4);text-decoration:none;">
                ⭐ Apply for Premium
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="stat-row">
      <div class="stat">
        <b><?= $myActive ?>/<?= isset($membership) && $membership ? (int)$membership['max_books'] : '3' ?></b>
        <span>Books Borrowed</span>
      </div>
      <div class="stat">
        <b style="color:<?= $myFines > 0 ? 'var(--danger)' : 'var(--navy)' ?>"><?= number_format($myFines, 2) ?></b>
        <span>Pending Fines</span>
      </div>
      <div class="stat"><b><?= $myReservations ?></b><span>Reservations</span></div>
      <div class="stat"><b><?= $myConnections ?></b><span>Connected Readers</span></div>
      <div class="stat"><b><?= $myExchangeBooks ?></b><span>Exchange Books</span></div>
    </div>

    <!-- User Action Toolbar -->
    <div class="toolbar" style="margin-bottom:24px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn-sm" style="text-decoration:none" href="books.php">🔍 Browse Catalog</a>
        <a class="btn-sm btn-secondary" style="text-decoration:none" href="borrowings.php">📖 My Borrowings</a>
        <a class="btn-sm btn-secondary" style="text-decoration:none" href="reservations.php">📌 My Reservations</a>
        <a class="btn-sm btn-secondary" style="text-decoration:none" href="fines.php">
          💰 My Fines <?= $myFines > 0 ? '<span class="tag bad" style="padding:1px 6px;margin-left:4px;">' . number_format($myFines, 2) . '</span>' : '' ?>
        </a>
        <a class="btn-sm" style="background:#0284c7;text-decoration:none" href="community.php">🤝 Community Hub</a>
        <a class="btn-sm" style="background:#d97706;text-decoration:none" href="membership.php">⭐ Membership</a>
      </div>
    </div>

    <!-- Active Borrowed Books Table -->
    <h3 style="margin-top:20px;">📖 My Currently Borrowed Books</h3>
    <table>
      <thead>
        <tr><th>Book Title</th><th>Barcode</th><th>Issued Date</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($activeLoans as $al): 
          $daysLate = overdue_days($al['due_date']);
        ?>
          <tr>
            <td><strong><?= e($al['title']) ?></strong></td>
            <td><code><?= e($al['barcode']) ?></code></td>
            <td><?= e($al['issue_date']) ?></td>
            <td class="<?= $daysLate > 0 ? 'overdue' : '' ?>">
              <?= e($al['due_date']) ?>
              <?= $daysLate > 0 ? " (+$daysLate d late)" : '' ?>
            </td>
            <td>
              <?php if ($daysLate > 0): ?>
                <span class="tag bad">Overdue</span>
              <?php else: ?>
                <span class="tag ok">Active Loan</span>
              <?php endif; ?>
            </td>
            <td class="actions">
              <form method="post" action="renew.php" style="display:inline">
                <input type="hidden" name="borrowing_id" value="<?= (int) $al['borrowing_id'] ?>">
                <button type="submit" class="btn-sm btn-secondary" <?= $daysLate > 0 ? 'disabled title="Cannot renew overdue book"' : '' ?>>Renew</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($activeLoans)): ?>
          <tr><td colspan="6">You have no active book borrowings at this time. <a href="books.php" style="color:var(--accent);font-weight:600;">Browse catalog</a> to borrow!</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

  <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>