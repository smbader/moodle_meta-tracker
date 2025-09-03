<?php
require_once __DIR__ . '/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$pageTitle = "Manage Credentials";
require_once __DIR__ . '/template/header.php';

// Connect to DB
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger'>Failed to connect to MySQL: " . $mysqli->connect_error . "</div>";
    require_once __DIR__ . '/template/footer.php';
    exit;
}

$user_id = $_SESSION['user_id'];
echo "Current User ID: " . htmlspecialchars($user_id);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['jira_email', 'jira_token', 'jira_domain', 'github_username', 'github_token'];
    foreach ($fields as $field) {
        $value = $mysqli->real_escape_string(isset($_POST[$field]) ? $_POST[$field] : '');
        $name = $mysqli->real_escape_string($field);
        // Upsert config value for this user
        $stmt = $mysqli->prepare("INSERT INTO config (user_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value=?");
        $stmt->bind_param("isss", $user_id, $name, $value, $value);
        if (!$stmt->execute()) {
            echo '<div class="alert alert-danger">SQL Error: ' . htmlspecialchars($stmt->error) . '</div>';
        }
        $stmt->close();
    }
    echo "<div class='alert alert-success'>Credentials saved.</div>";
}

// Fetch current values for this user
$config = [];
$stmt = $mysqli->prepare("SELECT name, value FROM config WHERE user_id = ? AND name IN ('jira_email','jira_token','jira_domain','github_username','github_token')");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $config[$row['name']] = $row['value'];
}
$stmt->close();
$mysqli->close();
?>
<h2>JIRA Credentials</h2>
<form method="post" class="mb-4">
  <div class="mb-3">
    <label for="jira_email" class="form-label">JIRA Email</label>
    <input type="email" class="form-control" id="jira_email" name="jira_email" value="<?php echo htmlspecialchars(isset($config['jira_email']) ? $config['jira_email'] : ''); ?>" required>
  </div>
  <div class="mb-3">
    <label for="jira_token" class="form-label">JIRA Token</label>
    <input type="text" class="form-control" id="jira_token" name="jira_token" value="<?php echo htmlspecialchars(isset($config['jira_token']) ? $config['jira_token'] : ''); ?>" required>
  </div>
  <div class="mb-3">
    <label for="jira_domain" class="form-label">JIRA Domain</label>
    <input type="text" class="form-control" id="jira_domain" name="jira_domain" value="<?php echo htmlspecialchars(isset($config['jira_domain']) ? $config['jira_domain'] : ''); ?>" required>
  </div>
  <h2>GitHub Credentials</h2>
  <div class="mb-3">
    <label for="github_username" class="form-label">GitHub Username</label>
    <input type="text" class="form-control" id="github_username" name="github_username" value="<?php echo htmlspecialchars(isset($config['github_username']) ? $config['github_username'] : ''); ?>" required>
  </div>
  <div class="mb-3">
    <label for="github_token" class="form-label">GitHub Token</label>
    <input type="text" class="form-control" id="github_token" name="github_token" value="<?php echo htmlspecialchars(isset($config['github_token']) ? $config['github_token'] : ''); ?>" required>
  </div>
  <button type="submit" class="btn btn-primary">Save</button>
</form>
<?php require_once __DIR__ . '/template/footer.php'; ?>
