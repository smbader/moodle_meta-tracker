<?php
require_once __DIR__ . '/config.php';
$pageTitle = "Kanban Board";
include __DIR__ . "/template/header.php";

function fetchJiraIssue($keyname, $jiraDomain, $jiraEmail, $jiraToken) {
    $url = $jiraDomain . "/rest/api/3/issue/" . urlencode($keyname);
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
    $summary = isset($data['fields']['summary']) ? $data['fields']['summary'] : $keyname;
    $status = isset($data['fields']['status']['name']) ? $data['fields']['status']['name'] : 'Unknown';
    $updated = isset($data['fields']['updated']) ? $data['fields']['updated'] : '';
    $assignee = isset($data['fields']['assignee']['displayName']) ? $data['fields']['assignee']['displayName'] : 'Unassigned';
    return [
        'summary' => $summary,
        'status' => $status,
        'keyname' => $keyname,
        'updated' => $updated,
        'assignee' => $assignee
    ];
}

// Ensure config variables are available
$DB_HOST = isset($DB_HOST) ? $DB_HOST : 'localhost';
$DB_PORT = isset($DB_PORT) ? $DB_PORT : 3306;
$DB_USER = isset($DB_USER) ? $DB_USER : 'root';
$DB_PASS = isset($DB_PASS) ? $DB_PASS : '';
$DB_NAME = isset($DB_NAME) ? $DB_NAME : '';
$JIRA_DOMAIN = isset($JIRA_DOMAIN) ? $JIRA_DOMAIN : '';
$JIRA_EMAIL = isset($JIRA_EMAIL) ? $JIRA_EMAIL : '';
$JIRA_API_TOKEN = isset($JIRA_API_TOKEN) ? $JIRA_API_TOKEN : '';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger'>Failed to connect to MySQL: " . $mysqli->connect_error . "</div>";
    include __DIR__ . "/template/footer.php";
    exit;
}

// Fetch all saved issues
$res = $mysqli->query("SELECT id, keyname FROM saved_issues");
$savedIssues = [];
while ($row = $res->fetch_assoc()) {
    $savedIssues[] = $row;
}
$res->close();
$mysqli->close();

// Fetch JIRA details for each issue
$issues = [];
$statuses = [];
foreach ($savedIssues as $issue) {
    $jira = fetchJiraIssue($issue['keyname'], $JIRA_DOMAIN, $JIRA_EMAIL, $JIRA_API_TOKEN);
    if ($jira) {
        $jira['id'] = $issue['id'];
        $issues[] = $jira;
        $statuses[$jira['status']] = true;
    }
}
$statuses = array_keys($statuses);

?>
<h1 class="mb-4">Kanban Board</h1>
<div class="row" style="overflow-x:auto; white-space:nowrap;">
<?php foreach ($statuses as $status): ?>
  <div class="col" style="min-width:300px; display:inline-block; vertical-align:top;">
    <div class="card mb-3">
      <div class="card-header bg-secondary text-white text-center">
        <strong><?= htmlspecialchars($status) ?></strong>
      </div>
      <div class="card-body" style="background:#f8f9fa; min-height:200px;">
        <?php foreach ($issues as $issue): ?>
          <?php if ($issue['status'] === $status): ?>
            <div class="card mb-2 shadow-sm" style="font-size:0.95em;">
              <div class="card-body p-2">
                <h6 class="card-title mb-1" style="font-size:1em; word-break:break-word; white-space:normal;">
                  #<?= $issue['id'] ?>: <?= htmlspecialchars($issue['summary']) ?>
                </h6>
                <div class="mb-1">
                  <span class="badge bg-info text-dark">Status: <?= htmlspecialchars($issue['status']) ?></span>
                  <span class="badge bg-secondary">Key: <?= htmlspecialchars($issue['keyname']) ?></span>
                </div>
                <div class="mb-1">
                  <span class="badge bg-light text-dark">Assignee: <?= htmlspecialchars($issue['assignee']) ?></span>
                </div>
                <div class="text-muted" style="font-size:0.85em;">Last updated: <?= $issue['updated'] ? date('Y-m-d H:i', strtotime($issue['updated'])) : 'N/A' ?></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . "/template/footer.php"; ?>
