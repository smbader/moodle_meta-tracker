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
$github_issues = [];

// Handle saving selected issues from the grid
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_selected_issues']) &&
    isset($_POST['selected_issues']) &&
    is_array($_POST['selected_issues'])
) {
    if (!$github_username || !$github_token) {
        $error_msg = 'GitHub credentials are not set. Please set them in your credentials.';
    } else {
        $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
        if ($mysqli->connect_errno) {
            $error_msg = 'Database connection error.';
        } else {
            $saved_count = 0;
            foreach ($_POST['selected_issues'] as $issue_json) {
                $issue = json_decode($issue_json, true);
                if (!$issue || !isset($issue['keyname'], $issue['title'], $issue['reporter'], $issue['status'])) continue;
                // Check if already saved
                $stmt = $mysqli->prepare("SELECT id FROM saved_issues WHERE user_id = ? AND keyname = ? AND source_type = 'github'");
                $stmt->bind_param("is", $user_id, $issue['keyname']);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 0) {
                    $stmt->close();
                    $stmt2 = $mysqli->prepare("INSERT INTO saved_issues (user_id, keyname, source_type, title, reporter, status) VALUES (?, ?, 'github', ?, ?, ?)");
                    $stmt2->bind_param("issss", $user_id, $issue['keyname'], $issue['title'], $issue['reporter'], $issue['status']);
                    if ($stmt2->execute()) {
                        $saved_count++;
                    }
                    $stmt2->close();
                } else {
                    $stmt->close();
                }
            }
            $mysqli->close();
            if ($saved_count > 0) {
                $success_msg = "$saved_count GitHub issue(s) saved successfully.";
            } else {
                $error_msg = 'No new issues were saved (they may already be saved).';
            }
        }
    }
}

// Fetch visible GitHub issues for the user (first 30)
if ($github_username && $github_token) {
    $api_url = 'https://api.github.com/issues?per_page=30';
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
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (is_array($data)) {
            foreach ($data as $issue) {
                if (!isset($issue['title'], $issue['user']['login'], $issue['created_at'], $issue['number'], $issue['repository']['full_name'])) continue;
                $keyname = $issue['repository']['full_name'] . '#' . $issue['number'];
                $github_issues[] = [
                    'keyname' => $keyname,
                    'title' => $issue['title'],
                    'reporter' => $issue['user']['login'],
                    'status' => $issue['state'],
                    'created_at' => $issue['created_at'],
                    'url' => $issue['html_url'],
                ];
            }
        }
    } else {
        $error_msg = 'Could not fetch GitHub issues. Check your token and permissions.';
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

  <?php if ($github_issues): ?>
    <h3>All Visible GitHub Issues</h3>
    <form method="post">
      <input type="hidden" name="save_selected_issues" value="1">
      <table class="table table-bordered table-striped">
        <thead>
          <tr>
            <th scope="col"><input type="checkbox" id="select_all"></th>
            <th scope="col">Title</th>
            <th scope="col">Author</th>
            <th scope="col">Submit Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($github_issues as $issue): ?>
            <tr>
              <td><input type="checkbox" name="selected_issues[]" value='<?php echo htmlspecialchars(json_encode($issue), ENT_QUOTES, 'UTF-8'); ?>'></td>
              <td><a href="<?php echo htmlspecialchars($issue['url']); ?>" target="_blank"><?php echo htmlspecialchars($issue['title']); ?></a></td>
              <td><?php echo htmlspecialchars($issue['reporter']); ?></td>
              <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($issue['created_at']))); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn btn-success">Save Selected Issues</button>
    </form>
    <script>
      // Select/Deselect all checkboxes
      document.addEventListener('DOMContentLoaded', function() {
        var selectAll = document.getElementById('select_all');
        if (selectAll) {
          selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="selected_issues[]"]');
            for (var i = 0; i < checkboxes.length; i++) {
              checkboxes[i].checked = selectAll.checked;
            }
          });
        }
      });
    </script>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/template/footer.php'; ?>
