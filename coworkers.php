<?php
require_once __DIR__ . '/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$pageTitle = "Coworkers Management";
require_once __DIR__ . '/template/header.php';

// DB connection
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger' role='alert'>Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . "</div>";
    require_once __DIR__ . '/template/footer.php';
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle add coworker
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $github_handle = trim($_POST['github_handle'] ?? '');
    if ($fullname && $email && $github_handle) {
        $stmt = $mysqli->prepare("INSERT INTO coworkers (user_id, fullname, email, github_handle) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id, $fullname, $email, $github_handle);
        $stmt->execute();
        $stmt->close();
    }
}

// Handle remove coworker
if (isset($_POST['remove_id'])) {
    $remove_id = intval($_POST['remove_id']);
    $stmt = $mysqli->prepare("DELETE FROM coworkers WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $remove_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

// Fetch all coworkers for this user
$coworkers = [];
$stmt = $mysqli->prepare("SELECT * FROM coworkers WHERE user_id = ? ORDER BY fullname ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $coworkers[] = $row;
}
$stmt->close();
$mysqli->close();
?>
<main id="main-content" tabindex="-1">
  <h1 class="visually-hidden">Coworkers Management</h1>
  <div class="row justify-content-center mb-4">
    <div class="col-md-8">
      <div class="card mb-4">
        <div class="card-header bg-primary text-white">Add Coworker</div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <div class="col-md-4">
              <label for="fullname" class="form-label">Full Name</label>
              <input type="text" name="fullname" id="fullname" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label for="email" class="form-label">Email Address</label>
              <input type="email" name="email" id="email" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label for="github_handle" class="form-label">GitHub Handle</label>
              <input type="text" name="github_handle" id="github_handle" class="form-control" required>
            </div>
            <div class="col-12">
              <button type="submit" name="add" class="btn btn-success">Add Coworker</button>
            </div>
          </form>
        </div>
      </div>
      <div class="card">
        <div class="card-header bg-primary text-white">Coworkers List</div>
        <div class="card-body">
          <table class="table table-striped table-bordered align-middle">
            <thead class="table-primary">
              <tr>
                <th>Full Name</th>
                <th>Email</th>
                <th>GitHub Handle</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($coworkers as $c): ?>
              <tr>
                <td><?php echo htmlspecialchars($c['fullname']); ?></td>
                <td style="white-space:nowrap;">
                  <?php echo htmlspecialchars($c['email']); ?>
                </td>
                <td><?php echo htmlspecialchars($c['github_handle']); ?></td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="remove_id" value="<?php echo $c['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove coworker?');">Remove</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (empty($coworkers)): ?>
            <div class="alert alert-info">No coworkers found.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/template/footer.php'; ?>
