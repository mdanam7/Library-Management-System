<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_staff();

$bookId = (int) ($_GET['book_id'] ?? $_POST['book_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM books WHERE book_id = ?');
$stmt->execute([$bookId]);
$book = $stmt->fetch();
if (!$book) { header('Location: books.php'); exit; }

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $status = $_POST['status'] ?? 'available';
        $location = trim($_POST['location'] ?? '');
        $next = (int) $pdo->query('SELECT COALESCE(MAX(copy_id),0) + 1 FROM book_copies')->fetchColumn();
        $barcode = 'BC-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('INSERT INTO book_copies (book_id, barcode, status, location, acquired_date) VALUES (?,?,?,?,CURDATE())');
        $stmt->execute([$bookId, $barcode, $status, $location]);
        log_activity($pdo, (int) $_SESSION['user_id'], 'COPY_ADD', "$barcode for book_id=$bookId");
        $msg = "Copy added with barcode $barcode.";
    } elseif ($action === 'status') {
        $copyId = (int) $_POST['copy_id'];
        $status = $_POST['status'];
        if (in_array($status, ['available', 'maintenance', 'lost'], true)) {
            $stmt = $pdo->prepare('UPDATE book_copies SET status = ? WHERE copy_id = ? AND status <> "borrowed"');
            $stmt->execute([$status, $copyId]);
            $msg = 'Copy status updated.';
        } else { $err = 'Copies that are borrowed/reserved must be processed via return/reservation.'; }
    } elseif ($action === 'delete') {
        $copyId = (int) $_POST['copy_id'];
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM borrowings WHERE copy_id = ?');
        $stmt->execute([$copyId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $err = 'Cannot delete a copy with borrowing history.';
        } else {
            $pdo->prepare('DELETE FROM book_copies WHERE copy_id = ?')->execute([$copyId]);
            $msg = 'Copy deleted.';
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM book_copies WHERE book_id = ? ORDER BY barcode');
$stmt->execute([$bookId]);
$copies = $stmt->fetchAll();

$page = 'Manage Copies';
include 'includes/header.php';
?>
<div class="card wide">
  <h2>Copies — <?= e($book['title']) ?></h2>
  <p class="sub">Physical inventory of this title</p>
  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <table>
    <tr><th>Barcode</th><th>Status</th><th>Location</th><th>Acquired</th><th>Actions</th></tr>
    <?php foreach ($copies as $c):
      $tag = $c['status'] === 'available' ? 'ok' : ($c['status'] === 'borrowed' ? 'bad' : 'warn'); ?>
    <tr>
      <td><strong><?= e($c['barcode']) ?></strong></td>
      <td><span class="tag <?= $tag ?>"><?= e($c['status']) ?></span></td>
      <td><?= e((string) $c['location']) ?></td>
      <td><?= e((string) $c['acquired_date']) ?></td>
      <td class="actions">
        <?php if ($c['status'] !== 'borrowed'): ?>
        <form method="post" style="display:inline-flex;gap:6px">
          <input type="hidden" name="book_id" value="<?= $bookId ?>">
          <input type="hidden" name="copy_id" value="<?= (int) $c['copy_id'] ?>">
          <select name="status">
            <?php foreach (['available', 'maintenance', 'lost'] as $s): ?>
              <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn-sm" name="action" value="status">Update</button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this copy?')">
          <input type="hidden" name="book_id" value="<?= $bookId ?>">
          <input type="hidden" name="copy_id" value="<?= (int) $c['copy_id'] ?>">
          <button class="btn-sm btn-danger" name="action" value="delete">Delete</button>
        </form>
        <?php else: ?><span class="tag bad">On loan</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$copies): ?><tr><td colspan="5">No copies yet — add the first one below.</td></tr><?php endif; ?>
  </table>

  <h2 style="margin-top:26px;font-size:1.1rem">Add a Copy</h2>
  <form method="post" class="toolbar" style="justify-content:flex-start">
    <input type="hidden" name="book_id" value="<?= $bookId ?>">
    <select name="status">
      <option value="available">available</option>
      <option value="maintenance">maintenance</option>
    </select>
    <input type="text" name="location" placeholder="Shelf location (e.g. Shelf A1)">
    <button class="btn-sm" name="action" value="add">＋ Add Copy</button>
  </form>
  <p class="hint"><a href="books.php">← Back to catalog</a></p>
</div>
<?php include 'includes/footer.php'; ?>