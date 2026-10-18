<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_staff();

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            try {
                $stmt = $pdo->prepare('INSERT INTO authors (author_name) VALUES (?)');
                $stmt->execute([$name]);
                $msg = 'Author added successfully.';
            } catch (PDOException $ex) { $err = 'That author already exists.'; }
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        try {
            $stmt = $pdo->prepare('DELETE FROM authors WHERE author_id = ?');
            $stmt->execute([$id]);
            $msg = 'Author deleted.';
        } catch (PDOException $ex) {
            $err = 'Cannot delete: this author is referenced by one or more books.';
        }
    }
}

$rows = $pdo->query(
    'SELECT t.*, (SELECT COUNT(*) FROM books b WHERE b.author_id = t.author_id) AS used_by
     FROM authors t ORDER BY author_name'
)->fetchAll();

$page = 'Authors';
include 'includes/header.php';
?>
<div class="card wide" style="max-width:760px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
    <div>
      <h2>✍️ Author Management</h2>
      <p class="sub" style="margin-bottom:0">Manage book authors in the library catalog</p>
    </div>
    <a href="books.php" class="btn-sm btn-secondary" style="text-decoration:none;">← Back to Catalog</a>
  </div>

  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="toolbar" style="margin-bottom:20px;">
    <input type="text" name="name" placeholder="Enter new author name..." required style="min-width:260px;">
    <button class="btn-sm" name="action" value="add">＋ Add Author</button>
  </form>

  <table>
    <thead>
      <tr><th>Author Name</th><th>Books in Catalog</th><th>Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['author_name']) ?></strong></td>
          <td><?= (int) $r['used_by'] ?> book(s)</td>
          <td class="actions">
            <?php if ((int) $r['used_by'] === 0): ?>
              <form method="post" onsubmit="return confirm('Delete this author?')">
                <input type="hidden" name="id" value="<?= (int) $r['author_id'] ?>">
                <button class="btn-sm btn-danger" name="action" value="delete">Delete</button>
              </form>
            <?php else: ?>
              <span class="tag info">In Use</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="3">No authors found. Add one above!</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php include 'includes/footer.php'; ?>
