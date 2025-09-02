<?php
require_once __DIR__ . '/config.php';
$pageTitle = "Manage Credentials";
require_once __DIR__ . '/template/header.php';

// Connect to DB
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger'>Failed to connect to MySQL: " . $mysqli->connect_error . "</div>";
    require_once __DIR__ . '/template/footer.php';
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['jira_email', 'jira_token', 'jira_domain'];
    foreach ($fields as $field) {
        $value = $mysqli->real_escape_string(isset($_POST[$field]) ? $_POST[$field] : '');
        $name = $mysqli->real_escape_string($field);
        // Upsert config value
        $mysqli->query("INSERT INTO config (name, value) VALUES ('$name', '$value') ON DUPLICATE KEY UPDATE value='$value'");
    }
    echo "<div class='alert alert-success'>Credentials saved.</div>";
}

// Fetch current values
$config = [];
$res = $mysqli->query("SELECT name, value FROM config WHERE name IN ('jira_email','jira_token','jira_domain')");
while ($row = $res->fetch_assoc()) {
    $config[$row['name']] = $row['value'];
}
$res->close();

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
  <button type="submit" class="btn btn-primary">Save</button>
</form>
<?php require_once __DIR__ . '/template/footer.php'; ?>
