<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_staff();

$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$book = ['title' => '', 'isbn' => '', 'author_id' => 0, 'category_id' => 0, 'publisher_id' => 0,
         'publication_year' => '', 'language' => 'English', 'description' => ''];

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM books WHERE book_id = ?');
    $stmt->execute([$id]);
    $book = $stmt->fetch() ?: $book;
    if (!$stmt->rowCount()) { header('Location: books.php'); exit; }
}

$authors     = $pdo->query('SELECT * FROM authors ORDER BY author_name')->fetchAll();
$categories  = $pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();
$publishers  = $pdo->query('SELECT * FROM publishers ORDER BY publisher_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($book as $k => $v) $book[$k] = $_POST[$k] ?? $v;
    $book['publication_year'] = (int) $book['publication_year'] ?: null;

    if ($book['title'] === '' || $book['isbn'] === '') $errors[] = 'Title and ISBN are required.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // resolve taxonomy: reuse selected, or create new when a name was typed
            foreach (['author' => 'authors', 'category' => 'categories', 'publisher' => 'publishers'] as $field => $table) {
                $newName = trim($_POST["new_{$field}"] ?? '');
                if ($newName !== '') {
                    $nameCol = $table === 'authors' ? 'author_name' : ($table === 'categories' ? 'category_name' : 'publisher_name');
                    $stmt = $pdo->prepare("SELECT {$field}_id FROM $table WHERE $nameCol = ?");
                    $stmt->execute([$newName]);
                    $found = $stmt->fetchColumn();
                    if (!$found) {
                        $stmt = $pdo->prepare("INSERT INTO $table ($nameCol) VALUES (?)");
                        $stmt->execute([$newName]);
                        $found = (int) $pdo->lastInsertId();
                    }
                    $book["{$field}_id"] = (int) $found;
                }
            }
            $stmt = $pdo->prepare('SELECT book_id FROM books WHERE isbn = ? AND book_id <> ?');
            $stmt->execute([$book['isbn'], $id]);
            if ($stmt->fetch()) $errors[] = 'A book with this ISBN already exists.';

            if (!$errors) {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE books SET title=?, isbn=?, author_id=?, category_id=?, publisher_id=?, publication_year=?, language=?, description=? WHERE book_id=?');
                    $stmt->execute([$book['title'], $book['isbn'], $book['author_id'], $book['category_id'],
                                    $book['publisher_id'], $book['publication_year'], $book['language'], $book['description'], $id]);
                    log_activity($pdo, (int) $_SESSION['user_id'], 'BOOK_UPDATE', "book_id=$id");
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO books (title, isbn, author_id, category_id, publisher_id, publication_year, language, description) VALUES (?,?,?,?,?,?,?,?)');
                    $stmt->execute([$book['title'], $book['isbn'], $book['author_id'], $book['category_id'],
                                    $book['publisher_id'], $book['publication_year'], $book['language'], $book['description']]);
                    log_activity($pdo, (int) $_SESSION['user_id'], 'BOOK_ADD', $book['title']);
                }
                $pdo->commit();
                header('Location: books.php?msg=' . urlencode($id > 0 ? 'Book updated.' : 'Book added — now add copies.'));
                exit;
            }
            $pdo->rollBack();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$page = $id > 0 ? 'Edit Book' : 'Add Book';
include 'includes/header.php';
?>
<div class="card wide">
  <h2><?= $id > 0 ? 'Edit Book' : 'Add New Book' ?></h2>
  <p class="sub">Fields marked * are required. Type a name in "new" to create it on the fly.</p>
  <?php foreach ($errors as $er): ?><div class="msg error"><?= e($er) ?></div><?php endforeach; ?>

  <form method="post" action="book_form.php<?= $id > 0 ? '?id=' . $id : '' ?>">
    <label>Title *</label>
    <input type="text" name="title" required value="<?= e($book['title']) ?>">

    <label>ISBN *</label>
    <input type="text" name="isbn" required value="<?= e($book['isbn']) ?>">

    <label>Author</label>
    <select name="author_id">
      <?php foreach ($authors as $a): ?><option value="<?= (int) $a['author_id'] ?>" <?= (int) $book['author_id'] === (int) $a['author_id'] ? 'selected' : '' ?>><?= e($a['author_name']) ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="new_author" placeholder="…or type a new author name" style="margin-top:6px">

    <label>Category</label>
    <select name="category_id">
      <?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>" <?= (int) $book['category_id'] === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="new_category" placeholder="…or type a new category" style="margin-top:6px">

    <label>Publisher</label>
    <select name="publisher_id">
      <?php foreach ($publishers as $p): ?><option value="<?= (int) $p['publisher_id'] ?>" <?= (int) $book['publisher_id'] === (int) $p['publisher_id'] ? 'selected' : '' ?>><?= e($p['publisher_name']) ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="new_publisher" placeholder="…or type a new publisher" style="margin-top:6px">

    <label>Publication Year</label>
    <input type="number" name="publication_year" min="1800" max="2100" value="<?= e((string) $book['publication_year']) ?>">

    <label>Language</label>
    <input type="text" name="language" value="<?= e($book['language']) ?>">

    <label>Description</label>
    <input type="text" name="description" value="<?= e((string) $book['description']) ?>">

    <button type="submit"><?= $id > 0 ? 'Save Changes' : 'Add Book' ?></button>
  </form>
  <p class="hint"><a href="books.php">← Back to catalog</a></p>
</div>
<?php include 'includes/footer.php'; ?>