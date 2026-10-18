<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_login();

$uid = (int) $_SESSION['user_id'];
$tab = $_GET['tab'] ?? 'connections';
$msg = $_SESSION['msg'] ?? '';
$msgType = $_SESSION['msg_type'] ?? 'info';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- 1. COMMUNITY CONNECTIONS ACTIONS ---
    if ($action === 'connect') {
        $receiverId = (int) ($_POST['receiver_id'] ?? 0);
        if ($receiverId > 0 && $receiverId !== $uid) {
            try {
                // Check existing connection pair in either direction
                $chk = $pdo->prepare('SELECT connection_id, status FROM community_connections WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)');
                $chk->execute([$uid, $receiverId, $receiverId, $uid]);
                $exists = $chk->fetch();

                if (!$exists) {
                    $stmt = $pdo->prepare('INSERT INTO community_connections (sender_id, receiver_id, status) VALUES (?, ?, "pending")');
                    $stmt->execute([$uid, $receiverId]);

                    // Audit Log
                    log_activity($pdo, $uid, 'community_connect_request', "Sent connection request to User ID #$receiverId");

                    $_SESSION['msg'] = 'Connection request sent successfully!';
                    $_SESSION['msg_type'] = 'success';
                } else {
                    $_SESSION['msg'] = 'A connection or request already exists with this member.';
                    $_SESSION['msg_type'] = 'error';
                }
            } catch (Exception $ex) {
                $_SESSION['msg'] = 'Error sending request: ' . $ex->getMessage();
                $_SESSION['msg_type'] = 'error';
            }
        }
        header('Location: community.php?tab=connections');
        exit;
    }

    if ($action === 'accept_connection') {
        $connId = (int) ($_POST['connection_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE community_connections SET status = "accepted" WHERE connection_id = ? AND receiver_id = ?');
        $stmt->execute([$connId, $uid]);

        if ($stmt->rowCount() > 0) {
            log_activity($pdo, $uid, 'community_connect_accepted', "Accepted Connection ID #$connId");
            $_SESSION['msg'] = 'Connection accepted!';
            $_SESSION['msg_type'] = 'success';
        }
        header('Location: community.php?tab=connections');
        exit;
    }

    if ($action === 'reject_connection' || $action === 'remove_connection') {
        $connId = (int) ($_POST['connection_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM community_connections WHERE connection_id = ? AND (sender_id = ? OR receiver_id = ?)');
        $stmt->execute([$connId, $uid, $uid]);

        $logText = ($action === 'reject_connection') ? "Declined Connection ID #$connId" : "Removed Connection ID #$connId";
        log_activity($pdo, $uid, 'community_connect_remove', $logText);

        $_SESSION['msg'] = ($action === 'reject_connection') ? 'Connection request declined.' : 'Connection removed.';
        $_SESSION['msg_type'] = 'info';
        header('Location: community.php?tab=connections');
        exit;
    }

    // --- 2. MEMBER BOOKS ACTIONS ---
    if ($action === 'add_book') {
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author_name'] ?? '');
        $condition = $_POST['book_condition'] ?? 'good';
        $description = trim($_POST['description'] ?? '');

        if (!in_array($condition, ['new', 'good', 'fair', 'poor'], true)) {
            $condition = 'good';
        }

        if ($title !== '') {
            $stmt = $pdo->prepare('INSERT INTO member_books (owner_id, title, author_name, book_condition, description, status) VALUES (?, ?, ?, ?, ?, "available")');
            $stmt->execute([$uid, $title, $author, $condition, $description]);
            $newId = $pdo->lastInsertId();

            log_activity($pdo, $uid, 'community_book_added', "Listed '$title' (ID #$newId) for exchange");

            $_SESSION['msg'] = 'Book listed for exchange successfully!';
            $_SESSION['msg_type'] = 'success';
        } else {
            $_SESSION['msg'] = 'Book title is required.';
            $_SESSION['msg_type'] = 'error';
        }
        header('Location: community.php?tab=my_books');
        exit;
    }

    if ($action === 'edit_book') {
        $bookId = (int) ($_POST['owned_book_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author_name'] ?? '');
        $condition = $_POST['book_condition'] ?? 'good';
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'available';

        if (in_array($condition, ['new', 'good', 'fair', 'poor'], true) && in_array($status, ['available', 'exchanged', 'unavailable'], true) && $title !== '') {
            $stmt = $pdo->prepare('UPDATE member_books SET title = ?, author_name = ?, book_condition = ?, description = ?, status = ? WHERE owned_book_id = ? AND owner_id = ?');
            $stmt->execute([$title, $author, $condition, $description, $status, $bookId, $uid]);

            log_activity($pdo, $uid, 'community_book_updated', "Updated book listing #$bookId ($title)");

            $_SESSION['msg'] = 'Book listing updated successfully.';
            $_SESSION['msg_type'] = 'success';
        }
        header('Location: community.php?tab=my_books');
        exit;
    }

    if ($action === 'update_book_status') {
        $bookId = (int) ($_POST['owned_book_id'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        if (in_array($status, ['available', 'exchanged', 'unavailable'], true)) {
            $stmt = $pdo->prepare('UPDATE member_books SET status = ? WHERE owned_book_id = ? AND owner_id = ?');
            $stmt->execute([$status, $bookId, $uid]);
            $_SESSION['msg'] = 'Book availability status updated.';
            $_SESSION['msg_type'] = 'success';
        }
        header('Location: community.php?tab=my_books');
        exit;
    }

    if ($action === 'delete_book') {
        $bookId = (int) ($_POST['owned_book_id'] ?? 0);

        // Delete associated pending exchange requests first if necessary
        $pdo->prepare('DELETE FROM exchange_requests WHERE owned_book_id = ?')->execute([$bookId]);
        $stmt = $pdo->prepare('DELETE FROM member_books WHERE owned_book_id = ? AND owner_id = ?');
        $stmt->execute([$bookId, $uid]);

        log_activity($pdo, $uid, 'community_book_deleted', "Deleted owned book listing #$bookId");

        $_SESSION['msg'] = 'Book listing deleted.';
        $_SESSION['msg_type'] = 'info';
        header('Location: community.php?tab=my_books');
        exit;
    }

    // --- 3. EXCHANGE REQUESTS ACTIONS ---
    if ($action === 'request_exchange') {
        $bookId = (int) ($_POST['owned_book_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        // Verify target book existence & status & non-ownership
        $bStmt = $pdo->prepare('SELECT owner_id, title, status FROM member_books WHERE owned_book_id = ?');
        $bStmt->execute([$bookId]);
        $book = $bStmt->fetch();

        if ($book && $book['owner_id'] !== $uid && $book['status'] === 'available') {
            // *** CONNECTION CHECK: Only connected members can exchange ***
            $connChk = $pdo->prepare(
                'SELECT connection_id FROM community_connections 
                 WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) 
                 AND status = "accepted"'
            );
            $connChk->execute([$uid, (int)$book['owner_id'], (int)$book['owner_id'], $uid]);
            if (!$connChk->fetch()) {
                $_SESSION['msg'] = '🔒 Exchange blocked: You must be connected with the book owner before requesting an exchange. Connect with them first from the Connections tab!';
                $_SESSION['msg_type'] = 'error';
                header('Location: community.php?tab=hub');
                exit;
            }

            // Check for existing pending request
            $dupChk = $pdo->prepare('SELECT exchange_id FROM exchange_requests WHERE requester_id = ? AND owned_book_id = ? AND status = "pending"');
            $dupChk->execute([$uid, $bookId]);
            if (!$dupChk->fetch()) {
                $stmt = $pdo->prepare('INSERT INTO exchange_requests (requester_id, owned_book_id, message, status) VALUES (?, ?, ?, "pending")');
                $stmt->execute([$uid, $bookId, $message]);
                $reqId = $pdo->lastInsertId();

                log_activity($pdo, $uid, 'community_exchange_requested', "Requested exchange for '{$book['title']}' (Request #$reqId)");

                $_SESSION['msg'] = '✓ Exchange request sent to book owner!';
                $_SESSION['msg_type'] = 'success';
            } else {
                $_SESSION['msg'] = 'You already have a pending exchange request for this book.';
                $_SESSION['msg_type'] = 'error';
            }
        } else {
            $_SESSION['msg'] = 'This book is unavailable or owned by you.';
            $_SESSION['msg_type'] = 'error';
        }
        header('Location: community.php?tab=requests');
        exit;
    }

    if ($action === 'respond_exchange') {
        $exchangeId = (int) ($_POST['exchange_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if (in_array($newStatus, ['accepted', 'rejected', 'completed'], true)) {
            $chk = $pdo->prepare('SELECT er.exchange_id, er.requester_id, mb.owned_book_id, mb.title 
                                  FROM exchange_requests er 
                                  JOIN member_books mb ON mb.owned_book_id = er.owned_book_id 
                                  WHERE er.exchange_id = ? AND mb.owner_id = ?');
            $chk->execute([$exchangeId, $uid]);
            $row = $chk->fetch();

            if ($row) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('UPDATE exchange_requests SET status = ? WHERE exchange_id = ?');
                    $stmt->execute([$newStatus, $exchangeId]);

                    // If completed, update member_books status to 'exchanged'
                    if ($newStatus === 'completed') {
                        $pdo->prepare('UPDATE member_books SET status = "exchanged" WHERE owned_book_id = ?')->execute([$row['owned_book_id']]);
                    }

                    $pdo->commit();

                    log_activity($pdo, $uid, 'community_exchange_response', "Set Exchange #$exchangeId status to '$newStatus'");

                    $_SESSION['msg'] = 'Exchange request status updated to ' . ucfirst($newStatus) . '.';
                    $_SESSION['msg_type'] = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['msg'] = 'Transaction failed: ' . $e->getMessage();
                    $_SESSION['msg_type'] = 'error';
                }
            }
        }
        header('Location: community.php?tab=requests');
        exit;
    }

    if ($action === 'cancel_exchange') {
        $exchangeId = (int) ($_POST['exchange_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE exchange_requests SET status = "cancelled" WHERE exchange_id = ? AND requester_id = ? AND status = "pending"');
        $stmt->execute([$exchangeId, $uid]);

        if ($stmt->rowCount() > 0) {
            log_activity($pdo, $uid, 'community_exchange_cancelled', "Cancelled Exchange Request #$exchangeId");
            $_SESSION['msg'] = 'Exchange request cancelled.';
            $_SESSION['msg_type'] = 'info';
        }
        header('Location: community.php?tab=requests');
        exit;
    }
}

// Data Counts for Tab Badges
$pendingConnCount = (int) $pdo->query("SELECT COUNT(*) FROM community_connections WHERE receiver_id = $uid AND status = 'pending'")->fetchColumn();
$incomingReqCount = (int) $pdo->query("SELECT COUNT(*) FROM exchange_requests er JOIN member_books mb ON mb.owned_book_id = er.owned_book_id WHERE mb.owner_id = $uid AND er.status = 'pending'")->fetchColumn();
$myBooksCount = (int) $pdo->query("SELECT COUNT(*) FROM member_books WHERE owner_id = $uid")->fetchColumn();

$page = 'Community';
include 'includes/header.php';
?>

<div class="card wide">
  <h2>🤝 Member Community &amp; Book Exchange</h2>
  <p class="sub">Connect with members, exchange personal books, and manage peer sharing according to the relational schema.</p>

  <?php if ($msg): ?>
    <div class="msg <?= e($msgType) ?>"><?= e($msg) ?></div>
  <?php endif; ?>

  <div class="nav-tabs">
    <a href="community.php?tab=connections" class="<?= $tab === 'connections' ? 'active' : '' ?>">
      Connections <?= $pendingConnCount > 0 ? '<span class="tab-badge">' . $pendingConnCount . '</span>' : '' ?>
    </a>
    <a href="community.php?tab=hub" class="<?= $tab === 'hub' ? 'active' : '' ?>">
      Exchange Hub
    </a>
    <a href="community.php?tab=my_books" class="<?= $tab === 'my_books' ? 'active' : '' ?>">
      My Listed Books <span class="tab-badge"><?= $myBooksCount ?></span>
    </a>
    <a href="community.php?tab=requests" class="<?= $tab === 'requests' ? 'active' : '' ?>">
      Exchange Requests <?= $incomingReqCount > 0 ? '<span class="tab-badge">' . $incomingReqCount . '</span>' : '' ?>
    </a>
  </div>

  <?php
  // ----------------------------------------------------
  // TAB 1: CONNECTIONS & MEMBER DIRECTORY
  // ----------------------------------------------------
  if ($tab === 'connections'):
      // Pending Incoming Connection Requests
      $pStmt = $pdo->prepare('SELECT c.connection_id, u.user_id, u.full_name, u.email, c.created_at 
                             FROM community_connections c 
                             JOIN users u ON u.user_id = c.sender_id 
                             WHERE c.receiver_id = ? AND c.status = "pending"
                             ORDER BY c.created_at DESC');
      $pStmt->execute([$uid]);
      $pendingRequests = $pStmt->fetchAll();

      // Accepted Connections
      $cStmt = $pdo->prepare('SELECT c.connection_id, u.user_id, u.full_name, u.email, c.created_at 
                             FROM community_connections c 
                             JOIN users u ON u.user_id = IF(c.sender_id = ?, c.receiver_id, c.sender_id) 
                             WHERE (c.sender_id = ? OR c.receiver_id = ?) AND c.status = "accepted"
                             ORDER BY u.full_name ASC');
      $cStmt->execute([$uid, $uid, $uid]);
      $connections = $cStmt->fetchAll();

      // Member Directory Search
      $search = trim($_GET['q'] ?? '');
      $sql = 'SELECT user_id, full_name, email, role, created_at FROM users WHERE role = "member" AND user_id != ?';
      $params = [$uid];
      if ($search !== '') {
          $sql .= ' AND (full_name LIKE ? OR email LIKE ?)';
          $params[] = "%$search%";
          $params[] = "%$search%";
      }
      $sql .= ' ORDER BY full_name ASC LIMIT 25';
      $dStmt = $pdo->prepare($sql);
      $dStmt->execute($params);
      $members = $dStmt->fetchAll();

      // Connection Status Map for Directory Users
      $connMap = [];
      $mapStmt = $pdo->prepare('SELECT connection_id, sender_id, receiver_id, status FROM community_connections WHERE sender_id = ? OR receiver_id = ?');
      $mapStmt->execute([$uid, $uid]);
      foreach ($mapStmt->fetchAll() as $cRow) {
          $otherId = ($cRow['sender_id'] === $uid) ? $cRow['receiver_id'] : $cRow['sender_id'];
          $connMap[$otherId] = [
              'connection_id' => $cRow['connection_id'],
              'status' => $cRow['status'],
              'is_sender' => ($cRow['sender_id'] === $uid)
          ];
      }
  ?>
    <?php if (!empty($pendingRequests)): ?>
      <h3>Pending Connection Requests</h3>
      <table>
        <thead>
          <tr>
            <th>Sender Name</th>
            <th>Email</th>
            <th>Requested On</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingRequests as $pr): ?>
            <tr>
              <td><strong><?= e($pr['full_name']) ?></strong></td>
              <td><?= e($pr['email']) ?></td>
              <td><?= date('M d, Y, h:i A', strtotime($pr['created_at'])) ?></td>
              <td class="actions">
                <form method="POST">
                  <input type="hidden" name="action" value="accept_connection">
                  <input type="hidden" name="connection_id" value="<?= (int)$pr['connection_id'] ?>">
                  <button type="submit" class="btn-sm">Accept</button>
                </form>
                <form method="POST">
                  <input type="hidden" name="action" value="reject_connection">
                  <input type="hidden" name="connection_id" value="<?= (int)$pr['connection_id'] ?>">
                  <button type="submit" class="btn-sm btn-secondary">Decline</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <br>
    <?php endif; ?>

    <h3>My Active Connections (<?= count($connections) ?>)</h3>
    <?php if (empty($connections)): ?>
      <p class="sub">You currently have no connected members. Connect with fellow readers in the directory below!</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Member</th>
            <th>Email</th>
            <th>Connected Since</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($connections as $c): ?>
            <tr>
              <td><strong><?= e($c['full_name']) ?></strong></td>
              <td><?= e($c['email']) ?></td>
              <td><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('Remove this connection?');">
                  <input type="hidden" name="action" value="remove_connection">
                  <input type="hidden" name="connection_id" value="<?= (int)$c['connection_id'] ?>">
                  <button type="submit" class="btn-sm btn-danger">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <br><hr><br>
    <h3>Member Directory</h3>
    <div class="toolbar">
      <form method="GET">
        <input type="hidden" name="tab" value="connections">
        <input type="text" name="q" placeholder="Search member name or email..." value="<?= e($search) ?>">
        <button type="submit" class="btn-sm">Search</button>
        <?php if ($search !== ''): ?>
          <a href="community.php?tab=connections" class="btn-sm btn-secondary" style="text-decoration:none">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <table>
      <thead>
        <tr>
          <th>Member Name</th>
          <th>Email</th>
          <th>Status / Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): 
          $mId = (int)$m['user_id'];
          $cState = $connMap[$mId] ?? null;
        ?>
          <tr>
            <td><strong><?= e($m['full_name']) ?></strong></td>
            <td><?= e($m['email']) ?></td>
            <td>
              <?php if (!$cState): ?>
                <form method="POST">
                  <input type="hidden" name="action" value="connect">
                  <input type="hidden" name="receiver_id" value="<?= $mId ?>">
                  <button type="submit" class="btn-sm">🤝 Connect</button>
                </form>
              <?php elseif ($cState['status'] === 'accepted'): ?>
                <span class="tag ok">✓ Connected</span>
              <?php elseif ($cState['status'] === 'pending'): ?>
                <?php if ($cState['is_sender']): ?>
                  <span class="tag info">⏳ Request Sent</span>
                <?php else: ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="accept_connection">
                    <input type="hidden" name="connection_id" value="<?= (int)$cState['connection_id'] ?>">
                    <button type="submit" class="btn-sm">Accept Request</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <span class="tag bad">Declined</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($members)): ?>
          <tr><td colspan="3">No members found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

  <?php
  // ----------------------------------------------------
  // TAB 2: EXCHANGE HUB (BROWSE COMMUNITY BOOKS)
  // ----------------------------------------------------
  elseif ($tab === 'hub'):
      $hSearch = trim($_GET['hq'] ?? '');
      $hCondition = $_GET['cond'] ?? '';
      $filterFriend = $_GET['friend'] ?? '';

      $sql = 'SELECT mb.*, u.full_name AS owner_name, u.email AS owner_email 
              FROM member_books mb 
              JOIN users u ON u.user_id = mb.owner_id 
              WHERE mb.status = "available" AND mb.owner_id != ?';
      $params = [$uid];

      if ($hSearch !== '') {
          $sql .= ' AND (mb.title LIKE ? OR mb.author_name LIKE ? OR mb.description LIKE ?)';
          $params[] = "%$hSearch%";
          $params[] = "%$hSearch%";
          $params[] = "%$hSearch%";
      }
      if ($hCondition !== '') {
          $sql .= ' AND mb.book_condition = ?';
          $params[] = $hCondition;
      }
      if ($filterFriend === '1') {
          $sql .= ' AND mb.owner_id IN (
                      SELECT IF(sender_id = ?, receiver_id, sender_id) 
                      FROM community_connections 
                      WHERE (sender_id = ? OR receiver_id = ?) AND status = "accepted"
                    )';
          $params[] = $uid;
          $params[] = $uid;
          $params[] = $uid;
      }
      $sql .= ' ORDER BY mb.created_at DESC';
      $hStmt = $pdo->prepare($sql);
      $hStmt->execute($params);
      $hubBooks = $hStmt->fetchAll();

      // Pre-fetch pending exchange requests by current user
      $myReqs = [];
      $rStmt = $pdo->prepare('SELECT owned_book_id FROM exchange_requests WHERE requester_id = ? AND status = "pending"');
      $rStmt->execute([$uid]);
      foreach ($rStmt->fetchAll() as $rRow) {
          $myReqs[$rRow['owned_book_id']] = true;
      }

      // Map accepted connection IDs
      $connectedOwnerIds = [];
      $coStmt = $pdo->prepare('SELECT IF(sender_id = ?, receiver_id, sender_id) AS friend_id FROM community_connections WHERE (sender_id = ? OR receiver_id = ?) AND status = "accepted"');
      $coStmt->execute([$uid, $uid, $uid]);
      foreach ($coStmt->fetchAll() as $coRow) {
          $connectedOwnerIds[$coRow['friend_id']] = true;
      }
  ?>
    <h3>Browse Community Exchange Catalog</h3>

    <!-- Connection Requirement Notice -->
    <div style="background:linear-gradient(135deg,#1e1b4b,#312e81);border:1px solid #4f46e5;border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;">
      <span style="font-size:1.5rem;flex-shrink:0;">🔗</span>
      <div>
        <strong style="color:#a5b4fc;font-size:.95rem;">Connection Required for Book Exchange</strong>
        <p style="color:#c7d2fe;font-size:.83rem;margin:4px 0 0;line-height:1.55;">
          You can only request a book exchange with members you are <strong>connected</strong> with. 
          Books from non-connected members show a 🔒 lock icon — connect with them first from the 
          <a href="community.php?tab=connections" style="color:#818cf8;">Connections tab</a>, then come back to exchange!
        </p>
      </div>
    </div>

    <div class="toolbar">
      <form method="GET">
        <input type="hidden" name="tab" value="hub">
        <input type="text" name="hq" placeholder="Title, author, or keyword..." value="<?= e($hSearch) ?>">
        <select name="cond">
          <option value="">All Conditions</option>
          <option value="new" <?= $hCondition === 'new' ? 'selected' : '' ?>>New</option>
          <option value="good" <?= $hCondition === 'good' ? 'selected' : '' ?>>Good</option>
          <option value="fair" <?= $hCondition === 'fair' ? 'selected' : '' ?>>Fair</option>
          <option value="poor" <?= $hCondition === 'poor' ? 'selected' : '' ?>>Poor</option>
        </select>
        <select name="friend">
          <option value="">All Members' Books</option>
          <option value="1" <?= $filterFriend === '1' ? 'selected' : '' ?>>Friends Only</option>
        </select>
        <button type="submit" class="btn-sm">Filter</button>
        <?php if ($hSearch !== '' || $hCondition !== '' || $filterFriend !== ''): ?>
          <a href="community.php?tab=hub" class="btn-sm btn-secondary" style="text-decoration:none">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($hubBooks)): ?>
      <p class="sub">No available books found matching your criteria. Try adjusting your filters!</p>
    <?php else: ?>
      <div class="grid-cards">
        <?php foreach ($hubBooks as $hb): 
          $bId = (int)$hb['owned_book_id'];
          $ownerId = (int)$hb['owner_id'];
          $isReqPending = isset($myReqs[$bId]);
          $isFriend = isset($connectedOwnerIds[$ownerId]);
          
          // Condition Tag Style mapping
          $condClass = 'info';
          if ($hb['book_condition'] === 'new') $condClass = 'ok';
          elseif ($hb['book_condition'] === 'good') $condClass = 'info';
          elseif ($hb['book_condition'] === 'fair') $condClass = 'warn';
          elseif ($hb['book_condition'] === 'poor') $condClass = 'bad';
        ?>
          <div class="item-card">
            <div>
              <h4><?= e($hb['title']) ?></h4>
              <p class="author">By <?= e($hb['author_name'] ?: 'Unknown Author') ?></p>
              <div class="meta">
                <span>Condition: <span class="tag <?= $condClass ?>"><?= ucfirst(e($hb['book_condition'])) ?></span></span><br>
                <span>Owner: <strong><?= e($hb['owner_name']) ?></strong> 
                  <?= $isFriend ? '<span class="tag ok" style="font-size:.68rem">🤝 Connected</span>' : '<span class="tag bad" style="font-size:.68rem">🔒 Not Connected</span>' ?>
                </span>
              </div>
              <?php if ($hb['description']): ?>
                <p style="font-size:.82rem;color:#4a5568;margin-bottom:12px"><?= nl2br(e($hb['description'])) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <?php if ($isReqPending): ?>
                <span class="tag warn" style="display:block;text-align:center;padding:7px;font-size:.8rem">⏳ Exchange Request Pending</span>
              <?php elseif (!$isFriend): ?>
                <!-- Not connected: block exchange with clear CTA -->
                <div style="text-align:center;padding:12px;background:#fef3c7;border:1.5px solid #fbbf24;border-radius:10px;">
                  <div style="font-size:1.4rem;margin-bottom:6px;">🔒</div>
                  <span style="font-size:.82rem;color:#78350f;display:block;margin-bottom:8px;font-weight:600;">Not Connected</span>
                  <p style="font-size:.76rem;color:#92400e;margin-bottom:10px;line-height:1.4;">Connect with <strong><?= e($hb['owner_name']) ?></strong> first to unlock book exchange.</p>
                  <a href="community.php?tab=connections&q=<?= urlencode($hb['owner_name']) ?>" class="btn-sm btn-secondary" style="text-decoration:none;font-size:.78rem;padding:6px 14px;display:inline-block;">🤝 Find &amp; Connect</a>
                </div>
              <?php else: ?>
                <form method="POST">
                  <input type="hidden" name="action" value="request_exchange">
                  <input type="hidden" name="owned_book_id" value="<?= $bId ?>">
                  <input type="text" name="message" placeholder="Include a message or proposed swap book..." style="font-size:.8rem;margin-bottom:6px">
                  <button type="submit" class="btn-sm btn-full">📤 Request Book Exchange</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php
  // ----------------------------------------------------
  // TAB 3: MY LISTED BOOKS (PERSONAL CATALOG)
  // ----------------------------------------------------
  elseif ($tab === 'my_books'):
      $editId = (int)($_GET['edit'] ?? 0);
      $editBook = null;
      if ($editId > 0) {
          $ebStmt = $pdo->prepare('SELECT * FROM member_books WHERE owned_book_id = ? AND owner_id = ?');
          $ebStmt->execute([$editId, $uid]);
          $editBook = $ebStmt->fetch();
      }

      $mbStmt = $pdo->prepare('SELECT mb.*, 
                              (SELECT COUNT(*) FROM exchange_requests er WHERE er.owned_book_id = mb.owned_book_id AND er.status = "pending") AS pending_req_count 
                              FROM member_books mb 
                              WHERE mb.owner_id = ? 
                              ORDER BY mb.created_at DESC');
      $mbStmt->execute([$uid]);
      $myBooks = $mbStmt->fetchAll();
  ?>
    <?php if ($editBook): ?>
      <h3>Edit Personal Book Listing</h3>
      <form method="POST" style="background:#f7fafc;padding:16px;border-radius:8px;border:1px solid #cbd5e0;margin-bottom:24px">
        <input type="hidden" name="action" value="edit_book">
        <input type="hidden" name="owned_book_id" value="<?= (int)$editBook['owned_book_id'] ?>">
        <div class="form-row">
          <div class="form-group">
            <label>Book Title *</label>
            <input type="text" name="title" required value="<?= e($editBook['title']) ?>">
          </div>
          <div class="form-group">
            <label>Author Name</label>
            <input type="text" name="author_name" value="<?= e($editBook['author_name']) ?>">
          </div>
          <div class="form-group">
            <label>Condition</label>
            <select name="book_condition">
              <option value="new" <?= $editBook['book_condition'] === 'new' ? 'selected' : '' ?>>New</option>
              <option value="good" <?= $editBook['book_condition'] === 'good' ? 'selected' : '' ?>>Good</option>
              <option value="fair" <?= $editBook['book_condition'] === 'fair' ? 'selected' : '' ?>>Fair</option>
              <option value="poor" <?= $editBook['book_condition'] === 'poor' ? 'selected' : '' ?>>Poor</option>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status">
              <option value="available" <?= $editBook['status'] === 'available' ? 'selected' : '' ?>>Available</option>
              <option value="exchanged" <?= $editBook['status'] === 'exchanged' ? 'selected' : '' ?>>Exchanged</option>
              <option value="unavailable" <?= $editBook['status'] === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
            </select>
          </div>
        </div>
        <div>
          <label>Description / Details</label>
          <textarea name="description" rows="2"><?= e($editBook['description']) ?></textarea>
        </div>
        <div style="margin-top:12px">
          <button type="submit" class="btn-sm" style="width:auto">Save Changes</button>
          <a href="community.php?tab=my_books" class="btn-sm btn-secondary" style="text-decoration:none;display:inline-block">Cancel</a>
        </div>
      </form>
    <?php else: ?>
      <h3>List a Personal Book for Exchange</h3>
      <form method="POST" style="background:#f7fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:24px">
        <input type="hidden" name="action" value="add_book">
        <div class="form-row">
          <div class="form-group">
            <label>Book Title *</label>
            <input type="text" name="title" required placeholder="e.g. Data Structures in C++">
          </div>
          <div class="form-group">
            <label>Author Name</label>
            <input type="text" name="author_name" placeholder="e.g. Mark Allen Weiss">
          </div>
          <div class="form-group">
            <label>Condition</label>
            <select name="book_condition">
              <option value="new">New</option>
              <option value="good" selected>Good</option>
              <option value="fair">Fair</option>
              <option value="poor">Poor</option>
            </select>
          </div>
        </div>
        <div>
          <label>Description / Details</label>
          <textarea name="description" rows="2" placeholder="Mention edition, contact info or preferred exchange books..."></textarea>
        </div>
        <button type="submit" class="btn-sm" style="margin-top:12px;width:auto">➕ Post Book for Exchange</button>
      </form>
    <?php endif; ?>

    <h3>My Personal Listings (<?= count($myBooks) ?>)</h3>
    <?php if (empty($myBooks)): ?>
      <p class="sub">You haven't added any personal books for exchange yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Title &amp; Author</th>
            <th>Condition</th>
            <th>Status</th>
            <th>Pending Requests</th>
            <th>Listed Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myBooks as $mb): 
            $bId = (int)$mb['owned_book_id'];
            $reqCount = (int)$mb['pending_req_count'];
          ?>
            <tr>
              <td>
                <strong><?= e($mb['title']) ?></strong>
                <div style="font-size:.8rem;color:var(--muted)"><?= e($mb['author_name'] ?: 'Unknown Author') ?></div>
              </td>
              <td><span class="tag info"><?= ucfirst(e($mb['book_condition'])) ?></span></td>
              <td>
                <?php if ($mb['status'] === 'available'): ?>
                  <span class="tag ok">Available</span>
                <?php elseif ($mb['status'] === 'exchanged'): ?>
                  <span class="tag warn">Exchanged</span>
                <?php else: ?>
                  <span class="tag bad">Unavailable</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($reqCount > 0): ?>
                  <a href="community.php?tab=requests" class="tag warn" style="text-decoration:none"><?= $reqCount ?> Request(s)</a>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:.8rem">0</span>
                <?php endif; ?>
              </td>
              <td><?= date('M d, Y', strtotime($mb['created_at'])) ?></td>
              <td class="actions">
                <a href="community.php?tab=my_books&edit=<?= $bId ?>" class="btn-sm btn-secondary" style="text-decoration:none;display:inline-block">Edit</a>
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this listing?');">
                  <input type="hidden" name="action" value="delete_book">
                  <input type="hidden" name="owned_book_id" value="<?= $bId ?>">
                  <button type="submit" class="btn-sm btn-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  <?php
  // ----------------------------------------------------
  // TAB 4: EXCHANGE REQUESTS WORKFLOW
  // ----------------------------------------------------
  elseif ($tab === 'requests'):
      // Incoming Requests
      $inStmt = $pdo->prepare('SELECT er.exchange_id, er.message, er.status, er.created_at, 
                                      mb.title AS book_title, u.full_name AS requester_name, u.email AS requester_email 
                               FROM exchange_requests er 
                               JOIN member_books mb ON mb.owned_book_id = er.owned_book_id 
                               JOIN users u ON u.user_id = er.requester_id 
                               WHERE mb.owner_id = ? 
                               ORDER BY er.created_at DESC');
      $inStmt->execute([$uid]);
      $incoming = $inStmt->fetchAll();

      // Outgoing Requests
      $outStmt = $pdo->prepare('SELECT er.exchange_id, er.message, er.status, er.created_at, 
                                       mb.title AS book_title, u.full_name AS owner_name, u.email AS owner_email 
                                FROM exchange_requests er 
                                JOIN member_books mb ON mb.owned_book_id = er.owned_book_id 
                                JOIN users u ON u.user_id = mb.owner_id 
                                WHERE er.requester_id = ? 
                                ORDER BY er.created_at DESC');
      $outStmt->execute([$uid]);
      $outgoing = $outStmt->fetchAll();
  ?>
    <h3>Incoming Exchange Requests (For My Listed Books)</h3>
    <?php if (empty($incoming)): ?>
      <p class="sub">No incoming exchange requests received yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Requested Book</th>
            <th>Requester</th>
            <th>Message / Note</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incoming as $inc): ?>
            <tr>
              <td><strong><?= e($inc['book_title']) ?></strong></td>
              <td>
                <?= e($inc['requester_name']) ?><br>
                <span style="font-size:.78rem;color:var(--muted)"><?= e($inc['requester_email']) ?></span>
              </td>
              <td><?= e($inc['message'] ?: '–') ?></td>
              <td>
                <?php if ($inc['status'] === 'pending'): ?>
                  <span class="tag warn">Pending</span>
                <?php elseif ($inc['status'] === 'accepted'): ?>
                  <span class="tag ok">Accepted</span>
                <?php elseif ($inc['status'] === 'completed'): ?>
                  <span class="tag info">Completed</span>
                <?php else: ?>
                  <span class="tag bad"><?= ucfirst(e($inc['status'])) ?></span>
                <?php endif; ?>
              </td>
              <td><?= date('M d, Y', strtotime($inc['created_at'])) ?></td>
              <td class="actions">
                <?php if ($inc['status'] === 'pending'): ?>
                  <form method="POST">
                    <input type="hidden" name="action" value="respond_exchange">
                    <input type="hidden" name="exchange_id" value="<?= (int)$inc['exchange_id'] ?>">
                    <input type="hidden" name="status" value="accepted">
                    <button type="submit" class="btn-sm">Accept</button>
                  </form>
                  <form method="POST">
                    <input type="hidden" name="action" value="respond_exchange">
                    <input type="hidden" name="exchange_id" value="<?= (int)$inc['exchange_id'] ?>">
                    <input type="hidden" name="status" value="rejected">
                    <button type="submit" class="btn-sm btn-secondary">Decline</button>
                  </form>
                <?php elseif ($inc['status'] === 'accepted'): ?>
                  <form method="POST">
                    <input type="hidden" name="action" value="respond_exchange">
                    <input type="hidden" name="exchange_id" value="<?= (int)$inc['exchange_id'] ?>">
                    <input type="hidden" name="status" value="completed">
                    <button type="submit" class="btn-sm">Mark Swap Completed</button>
                  </form>
                <?php else: ?>
                  –
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <br><hr><br>
    <h3>Outgoing Exchange Requests (Sent by Me)</h3>
    <?php if (empty($outgoing)): ?>
      <p class="sub">You haven't sent any exchange requests yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Book Title</th>
            <th>Book Owner</th>
            <th>Message</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outgoing as $out): ?>
            <tr>
              <td><strong><?= e($out['book_title']) ?></strong></td>
              <td>
                <?= e($out['owner_name']) ?><br>
                <span style="font-size:.78rem;color:var(--muted)"><?= e($out['owner_email']) ?></span>
              </td>
              <td><?= e($out['message'] ?: '–') ?></td>
              <td>
                <?php if ($out['status'] === 'pending'): ?>
                  <span class="tag warn">Pending</span>
                <?php elseif ($out['status'] === 'accepted'): ?>
                  <span class="tag ok">Accepted</span>
                <?php elseif ($out['status'] === 'completed'): ?>
                  <span class="tag info">Completed</span>
                <?php else: ?>
                  <span class="tag bad"><?= ucfirst(e($out['status'])) ?></span>
                <?php endif; ?>
              </td>
              <td><?= date('M d, Y', strtotime($out['created_at'])) ?></td>
              <td>
                <?php if ($out['status'] === 'pending'): ?>
                  <form method="POST">
                    <input type="hidden" name="action" value="cancel_exchange">
                    <input type="hidden" name="exchange_id" value="<?= (int)$out['exchange_id'] ?>">
                    <button type="submit" class="btn-sm btn-danger">Cancel Request</button>
                  </form>
                <?php else: ?>
                  –
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>
