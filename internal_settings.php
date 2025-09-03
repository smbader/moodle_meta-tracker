<?php
require_once __DIR__ . '/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
// Connect to DB using MySQLi
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger' role='alert'>Failed to connect to MySQL: " . $mysqli->connect_error . "</div>";
    require_once __DIR__ . '/template/footer.php';
    exit;
}
// Handle add, delete, reorder BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_status']) && !empty($_POST['status_name'])) {
        $stmt = $mysqli->prepare('SELECT MAX(sort_order) FROM statuses WHERE user_id = ?');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($maxOrder);
        $stmt->fetch();
        $stmt->close();
        $newOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;
        $stmt = $mysqli->prepare('INSERT INTO statuses (user_id, name, sort_order) VALUES (?, ?, ?)');
        $stmt->bind_param('isi', $user_id, $_POST['status_name'], $newOrder);
        $stmt->execute();
        $stmt->close();
    }
    if (isset($_POST['delete_status'])) {
        $stmt = $mysqli->prepare('DELETE FROM statuses WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $_POST['delete_status'], $user_id);
        $stmt->execute();
        $stmt->close();
    }
    if (isset($_POST['move_up']) || isset($_POST['move_down'])) {
        $id = isset($_POST['move_up']) ? $_POST['move_up'] : $_POST['move_down'];
        $direction = isset($_POST['move_up']) ? -1 : 1;
        $stmt = $mysqli->prepare('SELECT id, sort_order FROM statuses WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $user_id);
        $stmt->execute();
        $stmt->bind_result($current_id, $current_order);
        $stmt->fetch();
        $stmt->close();
        if ($current_id) {
            $swap_order = $current_order + $direction;
            $stmt = $mysqli->prepare('SELECT id, sort_order FROM statuses WHERE sort_order = ? AND user_id = ?');
            $stmt->bind_param('ii', $swap_order, $user_id);
            $stmt->execute();
            $stmt->bind_result($swap_id, $swap_sort_order);
            $stmt->fetch();
            $stmt->close();
            if ($swap_id) {
                $stmt = $mysqli->prepare('UPDATE statuses SET sort_order = ? WHERE id = ? AND user_id = ?');
                $stmt->bind_param('iii', $swap_sort_order, $current_id, $user_id);
                $stmt->execute();
                $stmt->close();
                $stmt = $mysqli->prepare('UPDATE statuses SET sort_order = ? WHERE id = ? AND user_id = ?');
                $stmt->bind_param('iii', $current_order, $swap_id, $user_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    header('Location: internal_settings.php');
    exit;
}
require_once __DIR__ . '/template/header.php';
// Fetch statuses for this user only
$statuses = [];
$stmt = $mysqli->prepare('SELECT * FROM statuses WHERE user_id = ? ORDER BY sort_order ASC');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $statuses[] = $row;
}
$stmt->close();
?>
<main id="main-content" tabindex="-1">
  <h1 class="visually-hidden">Internal Settings</h1>
  <div class="container mt-4">
    <h2>Manage Internal Statuses</h2>
    <form method="post" class="mb-3">
      <div class="input-group">
        <input type="text" name="status_name" class="form-control" placeholder="New status name" required>
        <button type="submit" name="add_status" class="btn btn-success">Add Status</button>
      </div>
    </form>
    <table class="table table-bordered">
      <thead><tr><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($statuses as $i => $status): ?>
          <tr>
            <td><?= htmlspecialchars($status['name']) ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="delete_status" value="<?= $status['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this status?')">Delete</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="move_up" value="<?= $status['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm" <?= $i === 0 ? 'disabled' : '' ?>>Up</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="move_down" value="<?= $status['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm" <?= $i === count($statuses)-1 ? 'disabled' : '' ?>>Down</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
<?php require_once __DIR__ . '/template/footer.php'; ?>
