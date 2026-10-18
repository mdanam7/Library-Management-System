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
                $stmt = $pdo->prepare('INSERT INTO categories (category_name) VALUES (?)');
                $stmt->execute([$name]);
                $msg = 'Category added successfully.';
            } catch (PDOException $ex) { $err = 'That category already exists.'; }
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        try {
            $stmt = $pdo->prepare('DELETE FROM categories WHERE category_id = ?');
            $stmt->execute([$id]);
            $msg = 'Category deleted.';
        } catch (PDOException $ex) {
            $err = 'Cannot delete: this category is referenced by one or more books.';
        }
    }
}

$rows = $pdo->query(
    'SELECT t.*, (SELECT COUNT(*) FROM books b WHERE b.category_id = t.category_id) AS used_by
     FROM categories t ORDER BY category_name'
)->fetchAll();

$page = 'Categories';
include 'includes/header.php';
?>
<div class="card wide" style="max-width:760px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
    <div>
      <h2>🏷️ Category Management</h2>
      <p class="sub" style="margin-bottom:0">Organize books into genres and subject classifications</p>
    </div>
    <a href="books.php" class="btn-sm btn-secondary" style="text-decoration:none;">← Back to Catalog</a>
  </div>

  <?php if ($msg): ?><div class="msg success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="toolbar" style="margin-bottom:20px;">
    <input type="text" name="name" placeholder="Enter new category name..." required style="min-width:260px;">
    <button class="btn-sm" name="action" value="add" style="width:auto;">＋ Add Category</button>
  </form>

  <table>
    <thead>
      <tr><th>Category Name</th><th>Books in Catalog</th><th>Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['category_name']) ?></strong></td>
          <td><?= (int) $r['used_by'] ?> book(s)</td>
          <td class="actions">
            <?php if ((int) $r['used_by'] === 0): ?>
              <form method="post" onsubmit="return confirm('Delete this category?')">
                <input type="hidden" name="id" value="<?= (int) $r['category_id'] ?>">
                <button class="btn-sm btn-danger" name="action" value="delete">Delete</button>
              </form>
            <?php else: ?>
              <span class="tag info">In Use</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="3">No categories found. Add one above!</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php include 'includes/footer.php'; ?>
