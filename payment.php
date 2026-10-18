<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$uid = (int) $_SESSION['user_id'];
$role = current_role();
$isStaff = in_array($role, ['admin', 'librarian'], true);

$fineId = (int) ($_GET['fine_id'] ?? $_POST['fine_id'] ?? 0);
$receiptView = isset($_GET['receipt']) && $_GET['receipt'] === '1';

if ($fineId <= 0) {
    header('Location: fines.php');
    exit;
}

// Fetch Fine & Borrowing Details
$stmt = $pdo->prepare(
    "SELECT f.*, b.title, u.full_name, u.email, bw.issue_date, bw.due_date, bw.user_id AS borrower_id, c.barcode
     FROM fines f
     JOIN borrowings bw ON bw.borrowing_id = f.borrowing_id
     JOIN book_copies c ON c.copy_id = bw.copy_id
     JOIN books b       ON b.book_id = c.book_id
     JOIN users u       ON u.user_id = bw.user_id
     WHERE f.fine_id = ?"
);
$stmt->execute([$fineId]);
$fine = $stmt->fetch();

if (!$fine) {
    header('Location: fines.php?err=' . urlencode('Fine record not found.'));
    exit;
}

// Authorization check: Only borrower or staff can access this fine's payment
if (!$isStaff && (int)$fine['borrower_id'] !== $uid) {
    header('Location: fines.php?err=' . urlencode('Unauthorized access to fine record.'));
    exit;
}

$msg = '';
$err = '';
$paymentRecord = null;

// If already paid, fetch payment details
if ($fine['status'] === 'paid') {
    $pStmt = $pdo->prepare("SELECT * FROM payments WHERE fine_id = ? ORDER BY payment_date DESC LIMIT 1");
    $pStmt->execute([$fineId]);
    $paymentRecord = $pStmt->fetch();
}

// Process Payment Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($fine['status'] === 'pending')) {
    $payMethod = trim($_POST['payment_method'] ?? 'Demo Card');
    $txnRef    = trim($_POST['transaction_ref'] ?? '');
    
    if (!$txnRef) {
        $txnRef = 'DEMO-TXN-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    try {
        $pdo->beginTransaction();

        // Lock row
        $lockStmt = $pdo->prepare("SELECT status, amount FROM fines WHERE fine_id = ? FOR UPDATE");
        $lockStmt->execute([$fineId]);
        $currFine = $lockStmt->fetch();

        if (!$currFine || $currFine['status'] !== 'pending') {
            throw new RuntimeException('This fine has already been paid or waived.');
        }

        $amount = (float) $currFine['amount'];

        // Record into payments table
        $insPay = $pdo->prepare(
            "INSERT INTO payments (fine_id, user_id, amount, payment_method, transaction_ref, payment_type, payment_date)
             VALUES (?, ?, ?, ?, ?, 'fine', NOW())"
        );
        $insPay->execute([$fineId, (int)$fine['borrower_id'], $amount, $payMethod, $txnRef]);
        $newPayId = $pdo->lastInsertId();

        // Update fine status
        $pdo->prepare("UPDATE fines SET status = 'paid', paid_date = NOW() WHERE fine_id = ?")->execute([$fineId]);

        log_activity($pdo, $uid, 'PAYMENT_COMPLETE', "fine_id=$fineId amount=$amount method=$payMethod txn=$txnRef");

        $pdo->commit();

        // Refresh fine record and payment record
        $fine['status'] = 'paid';
        $fine['paid_date'] = date('Y-m-d H:i:s');
        $paymentRecord = [
            'payment_id' => $newPayId,
            'fine_id' => $fineId,
            'amount' => $amount,
            'payment_method' => $payMethod,
            'transaction_ref' => $txnRef,
            'payment_date' => date('Y-m-d H:i:s')
        ];

        $msg = '✓ Dummy payment processed successfully! Your receipt is generated below.';
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $ex->getMessage();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = 'Payment failed due to server error: ' . $ex->getMessage();
    }
}

$page = ($fine['status'] === 'paid') ? 'Payment Receipt' : 'Fine Checkout';
include 'includes/header.php';
?>

<div class="card wide" style="max-width:860px;">
  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <?php if ($fine['status'] === 'paid' && $paymentRecord): ?>
    <!-- DIGITAL PAYMENT RECEIPT / INVOICE -->
    <div style="background:#fff;border:2px solid var(--border);border-radius:12px;padding:32px;box-shadow:var(--shadow-sm);" id="printable-receipt">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px dashed var(--border);padding-bottom:20px;margin-bottom:22px;flex-wrap:wrap;gap:12px;">
        <div>
          <span style="font-size:1.5rem;font-weight:800;color:var(--navy);">📚 UIU Library</span>
          <p style="font-size:.85rem;color:var(--muted);margin-top:2px;">Official Payment Receipt &amp; Clearance</p>
        </div>
        <div style="text-align:right;">
          <span class="tag ok" style="font-size:.88rem;padding:6px 14px;">✓ PAID IN FULL</span>
          <p style="font-size:.82rem;color:var(--muted);margin-top:6px;">Receipt #: <code>REC-<?= (int)$paymentRecord['payment_id'] ?></code></p>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-bottom:24px;">
        <div>
          <span style="font-size:.75rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Billed To</span>
          <div style="font-weight:700;font-size:1.05rem;color:var(--navy);margin-top:2px;"><?= e($fine['full_name']) ?></div>
          <small style="color:var(--muted);"><?= e($fine['email']) ?></small>
        </div>
        <div>
          <span style="font-size:.75rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Payment Details</span>
          <div style="font-weight:600;margin-top:2px;"><?= e($paymentRecord['payment_method']) ?></div>
          <small style="color:var(--muted);">Txn Ref: <code><?= e($paymentRecord['transaction_ref']) ?></code></small>
        </div>
        <div>
          <span style="font-size:.75rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Date &amp; Time</span>
          <div style="font-weight:600;margin-top:2px;"><?= date('M d, Y, h:i A', strtotime($paymentRecord['payment_date'])) ?></div>
          <small class="dummy-pill" style="margin-top:4px;">Demo Transaction</small>
        </div>
      </div>

      <!-- Item Table -->
      <table style="margin-bottom:20px;">
        <thead>
          <tr>
            <th>Item Description</th>
            <th>Barcode</th>
            <th>Due Date</th>
            <th style="text-align:right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong><?= e($fine['title']) ?></strong><br>
              <small style="color:var(--muted);"><?= e($fine['reason']) ?></small>
            </td>
            <td><code><?= e($fine['barcode']) ?></code></td>
            <td><?= e($fine['due_date']) ?></td>
            <td style="text-align:right;font-weight:700;font-size:1.05rem;">
              <?= number_format((float)$paymentRecord['amount'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr style="background:#f8fafc;">
            <th colspan="3" style="text-align:right;font-size:.9rem;">Total Settled:</th>
            <th style="text-align:right;font-size:1.25rem;color:var(--ok);font-weight:800;">
              <?= number_format((float)$paymentRecord['amount'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?>
            </th>
          </tr>
        </tfoot>
      </table>

      <div style="background:#f8fafc;padding:12px;border-radius:6px;font-size:.8rem;color:var(--muted);text-align:center;">
        Thank you for clearing your library dues. Your borrowing privileges are in good standing.
      </div>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap;">
      <button type="button" class="btn-sm btn-secondary" onclick="window.print()">🖨️ Print Receipt</button>
      <a href="fines.php" class="btn-sm" style="text-decoration:none;display:inline-flex;align-items:center;">← Return to Fines</a>
    </div>

  <?php else: ?>
    <!-- CHECKOUT & DUMMY PAYMENT FORM -->
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
      <div>
        <h2>💳 Settle Library Fine</h2>
        <p class="sub" style="margin-bottom:0">Review your overdue penalty and complete settlement via Dummy Payment Gateway.</p>
      </div>
      <div>
        <a href="fines.php" class="btn-sm btn-secondary" style="text-decoration:none;">Cancel &amp; Return</a>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:24px;margin-top:20px;">
      <!-- Order Summary Card -->
      <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:12px;padding:22px;height:fit-content;">
        <h4 style="color:var(--navy);font-size:1rem;margin-bottom:14px;border-bottom:1px solid var(--border);padding-bottom:8px;">
          📋 Invoice Summary
        </h4>
        <div style="margin-bottom:12px;">
          <small style="color:var(--muted);text-transform:uppercase;font-weight:700;">Book Title</small>
          <div style="font-weight:700;color:var(--navy);font-size:1.02rem;"><?= e($fine['title']) ?></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
          <div>
            <small style="color:var(--muted);font-weight:600;">Barcode</small>
            <div><code><?= e($fine['barcode']) ?></code></div>
          </div>
          <div>
            <small style="color:var(--muted);font-weight:600;">Due Date</small>
            <div style="color:var(--danger);font-weight:600;"><?= e($fine['due_date']) ?></div>
          </div>
        </div>

        <div style="margin-bottom:16px;">
          <small style="color:var(--muted);font-weight:600;">Imposed Reason</small>
          <div style="font-size:.88rem;color:#475569;"><?= e($fine['reason']) ?></div>
        </div>

        <div style="border-top:1.5px solid var(--border);padding-top:14px;display:flex;justify-content:space-between;align-items:center;">
          <span style="font-weight:600;color:#334155;">Total Payable:</span>
          <strong style="font-size:1.45rem;color:var(--danger);">
            <?= number_format((float)$fine['amount'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?>
          </strong>
        </div>
      </div>

      <!-- Payment Form Card -->
      <div style="background:#fff;border:1.5px solid var(--border);border-radius:12px;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
          <h4 style="color:var(--navy);font-size:1rem;">Select Payment Method</h4>
          <span class="dummy-pill">⚡ Sandbox Mode</span>
        </div>

        <button type="button" class="dummy-fill-btn btn-full" onclick="fillDummyFinePay()">
          ✨ Auto-Fill Dummy Test Transaction
        </button>

        <form method="POST" action="payment.php" id="payment-checkout-form">
          <input type="hidden" name="fine_id" value="<?= $fineId ?>">

          <label>Payment Channel</label>
          <select name="payment_method" id="pay-method" required onchange="togglePayInputs(this.value)">
            <option value="Demo Visa/Mastercard">Credit / Debit Card (Demo Visa/Mastercard)</option>
            <option value="Demo bKash">bKash (Demo MFS)</option>
            <option value="Demo Nagad">Nagad (Demo MFS)</option>
            <option value="Demo Rocket">Rocket (Demo MFS)</option>
          </select>

          <!-- Card Inputs Simulation -->
          <div id="card-fields" style="margin-top:12px;">
            <label>Card Number</label>
            <input type="text" id="demo-card-num" placeholder="4111 2222 3333 4444" maxlength="19">
            <div class="form-row" style="margin-top:10px;">
              <div class="form-group">
                <label>Expiration</label>
                <input type="text" id="demo-exp" placeholder="MM/YY" maxlength="5">
              </div>
              <div class="form-group">
                <label>CVV / CVC</label>
                <input type="password" id="demo-cvv" placeholder="123" maxlength="4">
              </div>
            </div>
          </div>

          <!-- MFS Fields Simulation (Initially hidden) -->
          <div id="mfs-fields" style="display:none;margin-top:12px;">
            <label>Mobile Account Number</label>
            <input type="text" id="demo-mfs-num" placeholder="01711-XXXXXX">
            <div class="form-row" style="margin-top:10px;">
              <div class="form-group">
                <label>Demo OTP Code</label>
                <input type="text" id="demo-otp" placeholder="123456" maxlength="6">
              </div>
              <div class="form-group">
                <label>Demo PIN</label>
                <input type="password" id="demo-pin" placeholder="••••" maxlength="5">
              </div>
            </div>
          </div>

          <div style="margin-top:12px;">
            <label>Transaction Reference</label>
            <input type="text" name="transaction_ref" id="demo-txn-ref" placeholder="Generated demo txn reference" required>
          </div>

          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:10px;margin-top:16px;font-size:.8rem;color:#1e40af;">
            🔒 Demonstration mode enabled. No real banking account will be charged. Clicking submit simulates complete gateway settlement.
          </div>

          <button type="submit" class="btn btn-success" style="margin-top:18px;">
            ✓ Confirm &amp; Pay <?= number_format((float)$fine['amount'], 2) ?> <?= e(get_setting($pdo, 'currency', 'BDT')) ?> (Demo)
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
function togglePayInputs(val) {
  var isCard = val.indexOf('Card') !== -1;
  document.getElementById('card-fields').style.display = isCard ? 'block' : 'none';
  document.getElementById('mfs-fields').style.display = isCard ? 'none' : 'block';
}

function fillDummyFinePay() {
  var method = document.getElementById('pay-method').value;
  var randomCode = Math.floor(100000 + Math.random() * 900000);
  document.getElementById('demo-txn-ref').value = 'DEMO-TXN-' + randomCode;

  if (method.indexOf('Card') !== -1) {
    document.getElementById('demo-card-num').value = '4111 2222 3333 4444';
    document.getElementById('demo-exp').value = '12/28';
    document.getElementById('demo-cvv').value = '789';
  } else {
    document.getElementById('demo-mfs-num').value = '01712-345678';
    document.getElementById('demo-otp').value = '654321';
    document.getElementById('demo-pin').value = '1234';
  }
}
</script>

<?php include 'includes/footer.php'; ?>
