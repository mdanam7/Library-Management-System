<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$isStaff = in_array(current_role(), ['admin', 'librarian'], true);
$q = trim($_GET['q'] ?? '');
$cat = (int) ($_GET['category'] ?? 0);

$sql = 'SELECT b.*, a.author_name, c.category_name, p.publisher_name,
        (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id) AS total,
        (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id AND bc.status = "available") AS available
        FROM books b
        JOIN authors a ON a.author_id = b.author_id
        JOIN categories c ON c.category_id = b.category_id
        JOIN publishers p ON p.publisher_id = b.publisher_id';
$cond = []; $params = [];
if ($q !== '') { $cond[] = '(b.title LIKE ? OR b.isbn LIKE ? OR a.author_name LIKE ?)'; $like = "%$q%"; array_push($params, $like, $like, $like); }
if ($cat > 0) { $cond[] = 'b.category_id = ?'; $params[] = $cat; }
if ($cond) $sql .= ' WHERE ' . implode(' AND ', $cond);
$sql .= ' ORDER BY b.title';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$books = $stmt->fetchAll();

$categories = $pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();
$uid = (int) $_SESSION['user_id'];

$page = 'Books';
include 'includes/header.php';
?>
<div class="card wide">
  <h2>Book Catalog</h2>
  <p class="sub"><?= count($books) ?> title(s) found</p>

  <div class="toolbar">
    <form method="get" action="books.php">
      <input type="text" name="q" placeholder="Search title, ISBN or author…" value="<?= e($q) ?>">
      <select name="category">
        <option value="0">All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['category_id'] ?>" <?= $cat === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn-sm" type="submit">Search</button>
    </form>
    <?php if ($isStaff): ?>
    <span>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="authors.php">Authors</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="categories.php">Categories</a>
      <a class="btn-sm btn-secondary" style="text-decoration:none" href="publishers.php">Publishers</a>
      <a class="btn-sm" style="text-decoration:none" href="book_form.php">＋ Add Book</a>
    </span>
    <?php endif; ?>
  </div>

  <?php if (isset($_GET['msg'])): ?><div class="msg success"><?= e($_GET['msg']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="msg error"><?= e($_GET['err']) ?></div><?php endif; ?>

  <table>
    <tr><th>Title</th><th>Author</th><th>Category</th><th>ISBN</th><th>Year</th><th>Availability</th><th>Actions</th></tr>
    <?php foreach ($books as $b):
        $av = (int) $b['available']; ?>
    <tr>
      <td><strong><?= e($b['title']) ?></strong></td>
      <td><?= e($b['author_name']) ?></td>
      <td><?= e($b['category_name']) ?></td>
      <td><?= e($b['isbn']) ?></td>
      <td><?= e((string) $b['publication_year']) ?></td>
      <td>
        <?php if ($av > 0): ?><span class="tag ok"><?= $av ?> of <?= (int) $b['total'] ?> available</span>
        <?php elseif ((int) $b['total'] === 0): ?><span class="tag warn">No copies</span>
        <?php else: ?><span class="tag bad">All borrowed</span><?php endif; ?>
      </td>
      <td class="actions">
        <?php if ($isStaff): ?>
          <a class="btn-sm btn-secondary" href="copies.php?book_id=<?= (int) $b['book_id'] ?>">Copies</a>
          <a class="btn-sm" href="book_form.php?id=<?= (int) $b['book_id'] ?>">Edit</a>
          <form method="post" action="book_delete.php" onsubmit="return confirm('Delete this book and all its copies?')">
            <input type="hidden" name="book_id" value="<?= (int) $b['book_id'] ?>">
            <button class="btn-sm btn-danger" type="submit">Delete</button>
          </form>
        <?php else:
            $res = $pdo->prepare("SELECT reservation_id FROM reservations WHERE user_id = ? AND book_id = ? AND status = 'pending'");
            $res->execute([$uid, (int) $b['book_id']]);
            if ($res->fetch()): ?>
              <span class="tag info">Reserved</span>
            <?php else: ?>
              <form method="post" action="reserve.php">
                <input type="hidden" name="book_id" value="<?= (int) $b['book_id'] ?>">
                <button class="btn-sm" type="submit">Reserve</button>
              </form>
            <?php endif;
        endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$books): ?><tr><td colspan="7">No books found.</td></tr><?php endif; ?>
  </table>
</div>
<?php include 'includes/footer.php'; ?>