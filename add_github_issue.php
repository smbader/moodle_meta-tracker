<?php
require_once __DIR__ . '/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$pageTitle = "Add GitHub Issue";
require_once __DIR__ . '/template/header.php';
$user_id = $_SESSION['user_id'];

// Fetch GitHub credentials
$github_username = getUserConfig($user_id, 'github_username');
$github_token = getUserConfig($user_id, 'github_token');

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['github_issue_link'])) {
    $link = trim($_POST['github_issue_link']);
    // Validate link format
    if (!preg_match('#^https://github.com/([\w\-\.]+)/([\w\-\.]+)/issues/(\d+)$#', $link, $matches)) {
        $error_msg = 'Invalid GitHub issue link format. Please use the format: https://github.com/owner/repo/issues/123';
    } elseif (!$github_username || !$github_token) {
        $error_msg = 'GitHub credentials are not set. Please set them in your credentials.';
    } else {
        $owner = $matches[1];
        $repo = $matches[2];
        $issue_number = $matches[3];
        $keyname = $owner . '/' . $repo . '#' . $issue_number;
        $api_url = "https://api.github.com/repos/$owner/$repo/issues/$issue_number";
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: META-Tracker',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'Authorization: Bearer ' . $github_token
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !$response) {
            $error_detail = htmlspecialchars($response);
            $error_msg = "Unable to access GitHub issue. <br>API URL: <code>$api_url</code><br>HTTP Code: $httpCode<br>Response: <code>$error_detail</code><br>Check that the link is correct, the issue exists, and your token has access.";
        } else {
            $data = json_decode($response, true);
            if (!$data || !isset($data['title'], $data['user']['login'], $data['state'])) {
                $error_msg = 'Could not parse GitHub issue data.';
            } else {
                $title = $data['title'];
                $reporter = $data['user']['login'];
                $status = $data['state'];
                // Save to DB
                $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
                if ($mysqli->connect_errno) {
                    $error_msg = 'Database connection error.';
                } else {
                    $stmt = $mysqli->prepare("INSERT INTO saved_issues (user_id, keyname, source_type, title, reporter, status) VALUES (?, ?, 'github', ?, ?, ?)");
                    $stmt->bind_param("issss", $user_id, $keyname, $title, $reporter, $status);
                    if ($stmt->execute()) {
                        $success_msg = 'GitHub issue saved successfully.';
                    } else {
                        $error_msg = 'Failed to save issue: ' . htmlspecialchars($stmt->error);
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        }
    }
}
?>
<div class="container mt-4">
  <h2>Add GitHub Issue</h2>
  <?php if ($error_msg): ?>
    <div class="alert alert-danger"><?php echo $error_msg; ?></div>
  <?php elseif ($success_msg): ?>
    <div class="alert alert-success"><?php echo $success_msg; ?></div>
  <?php endif; ?>
  <form method="post" class="mb-4">
    <div class="mb-3">
      <label for="github_issue_link" class="form-label">GitHub Issue Link</label>
      <input type="url" class="form-control" id="github_issue_link" name="github_issue_link" placeholder="https://github.com/owner/repo/issues/123" required>
    </div>
    <button type="submit" class="btn btn-primary">Add Issue</button>
  </form>
</div>
<?php require_once __DIR__ . '/template/footer.php'; ?>
