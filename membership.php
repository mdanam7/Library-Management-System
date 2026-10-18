<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$uid = (int) $_SESSION['user_id'];
$msg = $_SESSION['msg'] ?? '';
$msgType = $_SESSION['msg_type'] ?? 'info';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// Handle Premium Membership Application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_premium') {
        $durationMonths = (int) ($_POST['duration_months'] ?? 1);
        $paymentMethod  = trim($_POST['payment_method'] ?? 'demo-card');
        $transactionRef = trim($_POST['transaction_ref'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');

        // Pricing logic
        $feeMap = [
            1  => 200.00,
            3  => 550.00,
            6  => 1000.00,
            12 => 1800.00,
        ];
        $fee = $feeMap[$durationMonths] ?? 200.00;

        if (!$transactionRef) {
            $transactionRef = 'DEMO-TXN-PREM-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        // Check if there's already a pending request
        $chkStmt = $pdo->prepare("SELECT request_id FROM membership_requests WHERE user_id = ? AND status = 'pending'");
        $chkStmt->execute([$uid]);
        if ($chkStmt->fetch()) {
            $_SESSION['msg'] = 'You already have a pending Premium Membership application under review by the Administrator.';
            $_SESSION['msg_type'] = 'error';
        } else {
            // Fetch Premium Membership Type ID
            $pTypeStmt = $pdo->query("SELECT membership_type_id FROM membership_types WHERE type_name = 'Premium' LIMIT 1");
            $premTypeId = (int) $pTypeStmt->fetchColumn();
            if ($premTypeId <= 0) $premTypeId = 2;

            $insStmt = $pdo->prepare(
                "INSERT INTO membership_requests 
                 (user_id, membership_type_id, duration_months, fee, payment_method, transaction_ref, payment_status, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, 'pending')"
            );
            $insStmt->execute([$uid, $premTypeId, $durationMonths, $fee, $paymentMethod, $transactionRef, $notes]);
            $reqId = $pdo->lastInsertId();

            log_activity($pdo, $uid, 'MEMBERSHIP_APPLY', "Submitted Premium Request #$reqId ($durationMonths month(s), $fee BDT, Txn: $transactionRef)");

            $_SESSION['msg'] = "✓ Your application for Premium Membership (Ref #$transactionRef) was submitted successfully! It is now pending Admin approval.";
            $_SESSION['msg_type'] = 'success';
        }

        header('Location: membership.php');
        exit;
    }
}

// Fetch user's current active membership
$currentMem = user_membership($pdo, $uid);

// Fetch user's application history
$reqStmt = $pdo->prepare(
    "SELECT mr.*, mt.type_name, u_rev.full_name AS reviewer_name
     FROM membership_requests mr
     JOIN membership_types mt ON mt.membership_type_id = mr.membership_type_id
     LEFT JOIN users u_rev ON u_rev.user_id = mr.reviewed_by
     WHERE mr.user_id = ?
     ORDER BY mr.created_at DESC"
);
$reqStmt->execute([$uid]);
$myRequests = $reqStmt->fetchAll();

$page = 'Premium Membership';
include 'includes/header.php';
?>

<div class="card wide">
  <?php if ($msg): ?>
    <div class="msg <?= e($msgType) ?>"><?= e($msg) ?></div>
  <?php endif; ?>

  <!-- Premium Banner -->
  <div class="premium-hero-box">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;">
      <div>
        <span class="premium-badge-float">⭐ UIU Library Premium Club</span>
        <h2 style="color:#fff;font-size:1.6rem;margin-bottom:8px;">Elevate Your Library Experience</h2>
        <p style="color:#cbd5e1;font-size:.95rem;max-width:680px;line-height:1.6;">
          Unlock maximum borrowing capacity, extended loan durations, priority reservations, and exclusive privileges with a Premium Membership.
        </p>
      </div>
      <div>
        <?php if ($currentMem && $currentMem['type_name'] === 'Premium'): ?>
          <div style="background:rgba(16,185,129,0.2);border:2px solid #10b981;border-radius:12px;padding:16px 22px;text-align:center;">
            <span style="font-size:1.8rem;display:block;">⭐</span>
            <strong style="color:#6ee7b7;font-size:1.05rem;">Active Premium Member</strong>
            <p style="font-size:.8rem;color:#d1fae5;margin-top:4px;">
              <?= $currentMem['end_date'] ? 'Expires on: ' . date('M d, Y', strtotime($currentMem['end_date'])) : 'Active' ?>
            </p>
          </div>
        <?php else: ?>
          <div style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:12px;padding:16px 20px;text-align:center;">
            <span style="font-size:1.6rem;display:block;">📖</span>
            <strong style="color:#93c5fd;font-size:.95rem;">Current: Regular Member</strong>
            <p style="font-size:.78rem;color:#cbd5e1;margin-top:4px;">Free Standard Plan</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Comparison Feature Table -->
  <h3 style="margin-bottom:12px;">Plan Privileges Comparison</h3>
  <table style="margin-bottom:28px;">
    <thead>
      <tr>
        <th>Privilege / Feature</th>
        <th>Standard Regular (Free)</th>
        <th style="background:#eff6ff;color:var(--accent);">⭐ Premium Tier</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Max Borrowable Books at Once</strong></td>
        <td>3 Books</td>
        <td style="background:#f8fafc;"><strong style="color:var(--ok);">6 Books (Double Capacity)</strong></td>
      </tr>
      <tr>
        <td><strong>Loan Period per Book</strong></td>
        <td>14 Days</td>
        <td style="background:#f8fafc;"><strong style="color:var(--ok);">30 Days per Book</strong></td>
      </tr>
      <tr>
        <td><strong>Allowed Book Renewals</strong></td>
        <td>1 Time</td>
        <td style="background:#f8fafc;"><strong style="color:var(--ok);">3 Times</strong></td>
      </tr>
      <tr>
        <td><strong>Reservation Fulfillment Queue</strong></td>
        <td>Standard Priority</td>
        <td style="background:#f8fafc;"><strong style="color:#d97706;">⭐ High Priority Fast-Track</strong></td>
      </tr>
      <tr>
        <td><strong>Fine Grace Period</strong></td>
        <td>Standard (2 days)</td>
        <td style="background:#f8fafc;">Standard (2 days)</td>
      </tr>
    </tbody>
  </table>

  <!-- Application Form -->
  <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:12px;padding:26px;margin-bottom:30px;">
    <h3 style="color:var(--navy);font-size:1.15rem;margin-bottom:6px;">
      <?= ($currentMem && $currentMem['type_name'] === 'Premium') ? '🔄 Extend / Renew Premium Membership' : '✨ Apply for Premium Membership' ?>
    </h3>
    <p class="sub" style="margin-bottom:18px;">
      Choose your subscription duration and provide your payment details. You can use our dummy transaction simulator to test and demonstrate the complete flow.
    </p>

    <form method="POST" action="membership.php" id="prem-app-form">
      <input type="hidden" name="action" value="apply_premium">

      <!-- Plan Selection Cards -->
      <label style="margin-bottom:8px;">1. Select Subscription Term</label>
      <div class="plan-grid">
        <label class="plan-card" onclick="selectPlan(this, 1, 200)">
          <input type="radio" name="duration_months" value="1" checked style="display:none;">
          <span style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;">Monthly</span>
          <div class="price">200 <small style="font-size:.85rem;font-weight:600;">BDT</small></div>
          <span style="font-size:.78rem;color:#475569;">1 Month Premium access</span>
        </label>

        <label class="plan-card" onclick="selectPlan(this, 3, 550)">
          <input type="radio" name="duration_months" value="3" style="display:none;">
          <span style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;">Quarterly</span>
          <div class="price">550 <small style="font-size:.85rem;font-weight:600;">BDT</small></div>
          <span class="tag ok" style="font-size:.7rem;">Save 50 BDT</span>
        </label>

        <label class="plan-card" onclick="selectPlan(this, 6, 1000)">
          <input type="radio" name="duration_months" value="6" style="display:none;">
          <span style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;">Semester</span>
          <div class="price">1,000 <small style="font-size:.85rem;font-weight:600;">BDT</small></div>
          <span class="tag ok" style="font-size:.7rem;">Save 200 BDT</span>
        </label>

        <label class="plan-card" onclick="selectPlan(this, 12, 1800)">
          <input type="radio" name="duration_months" value="12" style="display:none;">
          <span class="tag gold" style="position:absolute;top:10px;right:10px;font-size:.65rem;">Best Value</span>
          <span style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;">Annual</span>
          <div class="price">1,800 <small style="font-size:.85rem;font-weight:600;">BDT</small></div>
          <span class="tag ok" style="font-size:.7rem;">Save 600 BDT</span>
        </label>
      </div>

      <!-- Payment & Dummy Transaction Details -->
      <div class="payment-box">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
          <div>
            <h4 style="color:var(--navy);font-size:.95rem;">2. Payment Method &amp; Transaction Details</h4>
            <span class="dummy-pill">⚡ Sandbox / Dummy Transaction Supported</span>
          </div>
          <button type="button" class="dummy-fill-btn" onclick="fillDummyPremPayment()">
            ✨ Fill Demo Transaction Details
          </button>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Payment Channel</label>
            <select name="payment_method" id="prem-method" required>
              <option value="Demo bKash">bKash (Demo MFS)</option>
              <option value="Demo Nagad">Nagad (Demo MFS)</option>
              <option value="Demo Card">Debit/Credit Card (Demo Gateway)</option>
              <option value="Demo Rocket">Rocket (Demo MFS)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Transaction Reference ID</label>
            <input type="text" name="transaction_ref" id="prem-txn-ref" placeholder="e.g. DEMO-TXN-PREM-84920" required>
          </div>
        </div>

        <div class="form-group">
          <label>Applicant Note / Purpose (Optional)</label>
          <input type="text" name="notes" id="prem-notes" placeholder="e.g. Thesis research semester, need extra books for capstone project">
        </div>

        <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-top:1px solid var(--border);padding-top:16px;">
          <div>
            <span style="font-size:.85rem;color:var(--muted);">Total Payable: </span>
            <strong id="display-fee" style="font-size:1.3rem;color:var(--navy);">200.00 BDT</strong>
          </div>
          <button type="submit" style="width:auto;margin:0;padding:11px 26px;background:#d97706;">
            ⭐ Submit Premium Application
          </button>
        </div>
      </div>
    </form>
  </div>

  <!-- User's Application History -->
  <h3>My Premium Application History</h3>
  <table>
    <thead>
      <tr>
        <th>App #</th>
        <th>Submitted Date</th>
        <th>Plan Duration</th>
        <th>Fee</th>
        <th>Transaction Ref</th>
        <th>Status</th>
        <th>Admin Feedback</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($myRequests as $req): 
        $st = $req['status'];
        $stTag = match($st) {
            'approved' => 'ok',
            'pending'  => 'warn',
            'rejected' => 'bad',
            default    => 'info'
        };
      ?>
        <tr>
          <td><code>#REQ-<?= (int)$req['request_id'] ?></code></td>
          <td><?= date('M d, Y, h:i A', strtotime($req['created_at'])) ?></td>
          <td><strong><?= (int)$req['duration_months'] ?> Month(s)</strong></td>
          <td><?= number_format((float)$req['fee'], 2) ?> BDT</td>
          <td><code><?= e($req['transaction_ref']) ?></code></td>
          <td>
            <span class="tag <?= $stTag ?>">
              <?= $st === 'approved' ? '✓ Approved' : ($st === 'pending' ? '⏳ Under Review' : '✗ Rejected') ?>
            </span>
          </td>
          <td>
            <?php if ($req['admin_notes']): ?>
              <small><?= e($req['admin_notes']) ?></small>
            <?php elseif ($st === 'approved'): ?>
              <small style="color:var(--ok);">Activated by <?= e($req['reviewer_name'] ?: 'Admin') ?></small>
            <?php else: ?>
              <small style="color:var(--muted);">–</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($myRequests)): ?>
        <tr><td colspan="7">No previous membership applications found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

</div>

<script>
function selectPlan(cardElem, months, fee) {
  document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
  cardElem.classList.add('selected');
  cardElem.querySelector('input[type="radio"]').checked = true;
  document.getElementById('display-fee').textContent = fee.toFixed(2) + ' BDT';
}

function fillDummyPremPayment() {
  var randomCode = Math.floor(100000 + Math.random() * 900000);
  document.getElementById('prem-txn-ref').value = 'DEMO-TXN-PREM-' + randomCode;
  document.getElementById('prem-notes').value = 'Demonstration payment for semester project research';
}

// Preselect first plan card on load
document.addEventListener('DOMContentLoaded', function() {
  var firstCard = document.querySelector('.plan-card');
  if (firstCard) firstCard.classList.add('selected');
});
</script>

<?php include 'includes/footer.php'; ?>
