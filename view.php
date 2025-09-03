<?php
require_once __DIR__ . '/config.php';
// Ensure DB config variables are in global scope
if (!isset($DB_HOST)) {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT;
}
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
include __DIR__ . "/template/header.php";

// Get keyname from query string
$key = isset($_GET['key']) ? trim($_GET['key']) : '';
if (!$key) {
    echo '<div class="alert alert-danger">No issue key provided.</div>';
    include __DIR__ . "/template/footer.php";
    exit();
}

// Fetch user-specific JIRA credentials
$user_id = $_SESSION['user_id'];

// --- New: Lookup issue in saved_issues table ---
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger'>Failed to connect to MySQL: " . $mysqli->connect_error . "</div>";
    include __DIR__ . "/template/footer.php";
    exit();
}
$stmt = $mysqli->prepare('SELECT * FROM saved_issues WHERE user_id = ? AND keyname = ? LIMIT 1');
$stmt->bind_param('is', $user_id, $key);
$stmt->execute();
$res = $stmt->get_result();
$savedIssue = $res->fetch_assoc();
$stmt->close();
if (!$savedIssue) {
    echo '<div class="alert alert-danger">Issue not found in your saved issues.</div>';
    include __DIR__ . "/template/footer.php";
    exit();
}
$sourceType = $savedIssue['source_type'];

if ($sourceType === 'jira') {
    $JIRA_DOMAIN = getUserConfig($user_id, 'jira_domain');
    $JIRA_EMAIL = getUserConfig($user_id, 'jira_email');
    $JIRA_API_TOKEN = getUserConfig($user_id, 'jira_token');
    // Fetch JIRA issue details
    function fetchJiraDetails($key, $jiraDomain, $jiraEmail, $jiraToken) {
        $url = $jiraDomain . "/rest/api/3/issue/" . urlencode($key);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $jiraEmail . ":" . $jiraToken);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !$response) return null;
        $data = json_decode($response, true);
        if (!$data) return null;
        return $data;
    }

    $issue = fetchJiraDetails($key, $JIRA_DOMAIN, $JIRA_EMAIL, $JIRA_API_TOKEN);
    if (!$issue) {
        echo '<div class="alert alert-danger">Unable to fetch JIRA issue details.</div>';
        include __DIR__ . "/template/footer.php";
        exit();
    }

    // Extract details
    $title = isset($issue['fields']['summary']) ? $issue['fields']['summary'] : $key;
    $desc = isset($issue['fields']['description']['content'][0]['content'][0]['text']) ? $issue['fields']['description']['content'][0]['content'][0]['text'] : '';
    $owner = isset($issue['fields']['assignee']['displayName']) ? $issue['fields']['assignee']['displayName'] : 'Unassigned';
    $status = isset($issue['fields']['status']['name']) ? $issue['fields']['status']['name'] : 'Unknown';

    // Find GitHub info in issue fields (custom field or in description)
    $githubUrl = '';
    if (isset($issue['fields']['customfield_github'])) {
        $githubUrl = $issue['fields']['customfield_github'];
    } elseif (strpos($desc, 'github.com/') !== false) {
        preg_match('/https:\/\/github.com\/[\w\-.]+\/[\w\-.]+/', $desc, $matches);
        if (!empty($matches)) {
            $githubUrl = $matches[0];
        }
    }

    // Helper to extract text from nested JIRA comment content
    function extractJiraCommentHtml($contentArr) {
        $html = '';
        foreach ($contentArr as $content) {
            if ($content['type'] === 'text' && isset($content['text'])) {
                $html .= htmlspecialchars($content['text']);
            } elseif ($content['type'] === 'paragraph' && isset($content['content'])) {
                $html .= '<p>' . extractJiraCommentHtml($content['content']) . '</p>';
            } elseif ($content['type'] === 'mention' && isset($content['attrs']['text'])) {
                $html .= '<span class="mention">' . htmlspecialchars($content['attrs']['text']) . '</span>';
            } elseif (isset($content['content'])) {
                $html .= extractJiraCommentHtml($content['content']);
            }
        }
        return $html;
    }

    // Get latest comment
    $latestComment = '';
    if (isset($issue['fields']['comment']['comments']) && count($issue['fields']['comment']['comments']) > 0) {
        $comments = $issue['fields']['comment']['comments'];
        $latestCommentContent = isset(end($comments)['body']['content']) ? end($comments)['body']['content'] : array();
        $latestComment = extractJiraCommentHtml($latestCommentContent);
    }

    // DB connection
    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    if ($mysqli->connect_errno) {
        echo "<div class='alert alert-danger'>Failed to connect to MySQL: " . $mysqli->connect_error . "</div>";
        include __DIR__ . "/template/footer.php";
        exit();
    }

    // Handle internal data form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['internal_save'])) {
        $internal_status_id = isset($_POST['internal_status_id']) ? intval($_POST['internal_status_id']) : null;
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $coworker_ids = isset($_POST['coworker_ids']) ? array_map('intval', $_POST['coworker_ids']) : array();
        // Upsert issue
        $stmt = $mysqli->prepare('SELECT id FROM issues WHERE keyname = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->bind_result($issue_id);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found) {
            $stmt = $mysqli->prepare('UPDATE issues SET internal_status_id = ?, notes = ? WHERE id = ?');
            $stmt->bind_param('isi', $internal_status_id, $notes, $issue_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare('INSERT INTO issues (keyname, title, `desc`, internal_status_id, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('sssis', $key, $title, $desc, $internal_status_id, $notes);
            $stmt->execute();
            $issue_id = $stmt->insert_id;
            $stmt->close();
        }
        // Update coworkers
        $stmt = $mysqli->prepare('DELETE FROM issue_coworkers WHERE issue_id = ?');
        $stmt->bind_param('i', $issue_id);
        $stmt->execute();
        $stmt->close();
        foreach ($coworker_ids as $cid) {
            $stmt = $mysqli->prepare('INSERT INTO issue_coworkers (issue_id, coworker_id) VALUES (?, ?)');
            $stmt->bind_param('ii', $issue_id, $cid);
            $stmt->execute();
            $stmt->close();
        }
        $success_msg = 'Internal data saved.';
    }
    // Load internal data
    $stmt = $mysqli->prepare('SELECT * FROM issues WHERE keyname = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $internal = $res->fetch_assoc();
    $stmt->close();
    $selected_status = $internal ? $internal['internal_status_id'] : '';
    $notes_val = $internal ? $internal['notes'] : '';
    $issue_id = $internal ? $internal['id'] : null;
    // Load assigned coworkers
    $assigned_coworkers = array();
    if ($issue_id) {
        $stmt = $mysqli->prepare('SELECT coworker_id FROM issue_coworkers WHERE issue_id = ?');
        $stmt->bind_param('i', $issue_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $assigned_coworkers[] = $row['coworker_id'];
        }
        $stmt->close();
    }
    // Load all statuses for this user only
    $statuses = [];
    $stmt = $mysqli->prepare('SELECT * FROM statuses WHERE user_id = ? ORDER BY sort_order ASC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $statuses[] = $row;
    }
    $stmt->close();
    // Load all coworkers
    $coworkers = [];
    $res = $mysqli->query('SELECT * FROM coworkers ORDER BY fullname ASC');
    while ($row = $res->fetch_assoc()) {
        $coworkers[] = $row;
    }
    $res->close();
} elseif ($sourceType === 'github') {
    // --- New: Extract GitHub repo and issue number from keyname ---
    // keyname format: owner/repo#issue_number
    $githubRepo = '';
    $githubIssueNumber = '';
    if (preg_match('#^([\w\-.]+)/([\w\-.]+)\#(\d+)$#', $savedIssue['keyname'], $matches)) {
        $githubRepo = $matches[1] . '/' . $matches[2];
        $githubIssueNumber = $matches[3];
    }
    $githubToken = getUserConfig($user_id, 'github_token');
    if (!$githubRepo || !$githubIssueNumber || !$githubToken) {
        echo '<div class="alert alert-danger">Missing GitHub repo, issue number, or token.</div>';
        include __DIR__ . "/template/footer.php";
        exit();
    }
    $githubApiUrl = "https://api.github.com/repos/$githubRepo/issues/$githubIssueNumber";
    $ch = curl_init($githubApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'IssueViewerApp');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $githubToken
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$response) {
        echo '<div class="alert alert-danger">Unable to fetch GitHub issue details.</div>';
        include __DIR__ . "/template/footer.php";
        exit();
    }
    $issue = json_decode($response, true);
    // Extract details similar to JIRA
    $title = isset($issue['title']) ? $issue['title'] : $githubIssueNumber;
    $desc = isset($issue['body']) ? $issue['body'] : '';
    $owner = (isset($issue['assignee']) && isset($issue['assignee']['login'])) ? $issue['assignee']['login'] : 'Unassigned';
    $status = isset($issue['state']) ? $issue['state'] : 'Unknown';
    $latestComment = '';
    if (!empty($issue['comments'])) {
        // Fetch latest comment if any
        $commentsUrl = $issue['comments_url'];
        $ch = curl_init($commentsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IssueViewerApp');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $githubToken
        ]);
        $commentsResp = curl_exec($ch);
        curl_close($ch);
        $commentsArr = json_decode($commentsResp, true);
        if (is_array($commentsArr) && count($commentsArr) > 0) {
            $lastComment = end($commentsArr);
            $latestComment = isset($lastComment['body']) ? $lastComment['body'] : '';
        }
    }
    // --- Internal Issue Data (same as JIRA) ---
    // Handle internal data form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['internal_save'])) {
        $internal_status_id = isset($_POST['internal_status_id']) ? intval($_POST['internal_status_id']) : null;
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $coworker_ids = isset($_POST['coworker_ids']) ? array_map('intval', $_POST['coworker_ids']) : array();
        // Upsert issue
        $stmt = $mysqli->prepare('SELECT id FROM issues WHERE keyname = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->bind_result($issue_id);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found) {
            $stmt = $mysqli->prepare('UPDATE issues SET internal_status_id = ?, notes = ? WHERE id = ?');
            $stmt->bind_param('isi', $internal_status_id, $notes, $issue_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare('INSERT INTO issues (keyname, title, `desc`, internal_status_id, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('sssis', $key, $title, $desc, $internal_status_id, $notes);
            $stmt->execute();
            $issue_id = $stmt->insert_id;
            $stmt->close();
        }
        // Update coworkers
        $stmt = $mysqli->prepare('DELETE FROM issue_coworkers WHERE issue_id = ?');
        $stmt->bind_param('i', $issue_id);
        $stmt->execute();
        $stmt->close();
        foreach ($coworker_ids as $cid) {
            $stmt = $mysqli->prepare('INSERT INTO issue_coworkers (issue_id, coworker_id) VALUES (?, ?)');
            $stmt->bind_param('ii', $issue_id, $cid);
            $stmt->execute();
            $stmt->close();
        }
        $success_msg = 'Internal data saved.';
    }
    // Load internal data
    $stmt = $mysqli->prepare('SELECT * FROM issues WHERE keyname = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $internal = $res->fetch_assoc();
    $stmt->close();
    $selected_status = $internal ? $internal['internal_status_id'] : '';
    $notes_val = $internal ? $internal['notes'] : '';
    $issue_id = $internal ? $internal['id'] : null;
    // Load assigned coworkers
    $assigned_coworkers = array();
    if ($issue_id) {
        $stmt = $mysqli->prepare('SELECT coworker_id FROM issue_coworkers WHERE issue_id = ?');
        $stmt->bind_param('i', $issue_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $assigned_coworkers[] = $row['coworker_id'];
        }
        $stmt->close();
    }
    // Load all statuses for this user only
    $statuses = [];
    $stmt = $mysqli->prepare('SELECT * FROM statuses WHERE user_id = ? ORDER BY sort_order ASC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $statuses[] = $row;
    }
    $stmt->close();
    // Load all coworkers
    $coworkers = [];
    $res = $mysqli->query('SELECT * FROM coworkers ORDER BY fullname ASC');
    while ($row = $res->fetch_assoc()) {
        $coworkers[] = $row;
    }
    $res->close();
    // Display GitHub issue details (do not exit, let the shared form render below)
    $githubUrl = 'https://github.com/' . $githubRepo . '/issues/' . $githubIssueNumber;
}
?>
<div class="container mt-4">
  <div class="row">
    <div class="col-md-6 col-12">
      <h2 class="mb-2" style="font-size:1.5rem; word-break:break-word; white-space:normal; line-height:1.2;">
        <span class="badge bg-secondary" style="font-size:1rem; vertical-align:middle;"><?= htmlspecialchars($key) ?></span>
        <?= htmlspecialchars($title) ?>
      </h2>
      <div class="mb-3"><strong>Status:</strong> <?= htmlspecialchars($status) ?></div>
      <div class="mb-3"><strong>Assignee:</strong> <?= htmlspecialchars($owner) ?></div>
      <div class="mb-3"><strong>Description:</strong><br><?= nl2br(htmlspecialchars($desc)) ?></div>
      <?php if ($latestComment): ?>
        <div class="mb-3"><strong>Latest Comment:</strong><br><?= $latestComment ?></div>
      <?php endif; ?>
      <?php if ($sourceType === 'jira' && !empty($JIRA_DOMAIN)): ?>
        <div class="mt-4">
          <a href="<?= htmlspecialchars($JIRA_DOMAIN) ?>/browse/<?= rawurlencode($key) ?>" target="_blank" class="btn btn-primary">View in JIRA</a>
        </div>
      <?php elseif ($sourceType === 'github' && !empty($githubUrl)): ?>
        <div class="mt-4">
          <a href="<?= htmlspecialchars($githubUrl) ?>" target="_blank" class="btn btn-dark">View in GitHub</a>
        </div>
      <?php endif; ?>
    </div>
    <div class="col-md-6 col-12">
      <h4>Internal Issue Data</h4>
      <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label for="internal_status_id" class="form-label">Internal Status</label>
          <select name="internal_status_id" id="internal_status_id" class="form-select">
            <option value="">-- Select --</option>
            <?php foreach ($statuses as $status): ?>
              <option value="<?= $status['id'] ?>" <?= ($selected_status == $status['id']) ? 'selected' : '' ?>><?= htmlspecialchars($status['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label for="coworker_ids" class="form-label">Assigned Coworkers</label>
          <select name="coworker_ids[]" id="coworker_ids" class="form-select" multiple>
            <?php foreach ($coworkers as $coworker): ?>
              <option value="<?= $coworker['id'] ?>" <?= in_array($coworker['id'], $assigned_coworkers) ? 'selected' : '' ?>><?= htmlspecialchars($coworker['fullname']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label for="notes" class="form-label">Notes</label>
          <textarea name="notes" id="notes" class="form-control" rows="4"><?= htmlspecialchars($notes_val) ?></textarea>
        </div>
        <button type="submit" name="internal_save" class="btn btn-primary">Save Internal Data</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . "/template/footer.php"; ?>
