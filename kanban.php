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

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger'>Failed to connect to MySQL: " . $mysqli->connect_error . "</div>";
    include __DIR__ . "/template/footer.php";
    exit;
}

$user_id = $_SESSION['user_id'];
$JIRA_DOMAIN = getUserConfig($user_id, 'jira_domain');
$JIRA_EMAIL = getUserConfig($user_id, 'jira_email');
$JIRA_API_TOKEN = getUserConfig($user_id, 'jira_token');

// Fetch all internal statuses for this user
$statuses = [];
$stmt = $mysqli->prepare("SELECT id, name FROM statuses WHERE user_id = ? ORDER BY sort_order ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $statuses[] = $row;
}
$stmt->close();

// Add a pseudo-status for issues with no internal status as the first column
$noStatusId = 'none';
$columns = array_merge([
    ['id' => $noStatusId, 'name' => 'No Internal Status']
], $statuses);

// Fetch all saved_issues for the user
$savedIssues = [];
$stmt = $mysqli->prepare("SELECT * FROM saved_issues WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $savedIssues[$row['keyname']] = $row;
}
$stmt->close();

// Fetch all internal issues for the user, indexed by keyname
$internalIssues = [];
$stmt = $mysqli->prepare("SELECT * FROM issues WHERE internal_status_id IN (SELECT id FROM statuses WHERE user_id = ?) OR internal_status_id = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $internalIssues[$row['keyname']] = $row;
}
$stmt->close();

// Merge saved_issues with internal issues
$allIssues = [];
foreach ($savedIssues as $keyname => $saved) {
    $merged = $saved;
    if (isset($internalIssues[$keyname])) {
        // Merge, internal fields take lower priority
        $merged = array_merge($internalIssues[$keyname], $saved);
        // Always keep the issues.id as internal_issue_id
        $merged['internal_issue_id'] = $internalIssues[$keyname]['id'];
    } else {
        $merged['internal_issue_id'] = null;
    }
    $allIssues[] = $merged;
}
$mysqli->close();

// --- TAG FETCH: Attach tags to all issues ---
// Collect all internal_issue_ids (non-null)
$internalIssueIds = array();
foreach ($allIssues as $issue) {
    if (!empty($issue['internal_issue_id'])) {
        $internalIssueIds[] = (int)$issue['internal_issue_id'];
    }
}
$internalIssueIds = array_unique($internalIssueIds);
$issueTagsMap = array(); // internal_issue_id => [tag1, tag2, ...]
if (!empty($internalIssueIds)) {
    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $idsStr = implode(',', $internalIssueIds);
    $sql = "SELECT it.issue_id, t.name FROM issue_tags it JOIN tags t ON it.tag_id = t.id WHERE it.issue_id IN ($idsStr) ORDER BY t.name ASC";
    $res = $mysqli->query($sql);
    while ($row = $res->fetch_assoc()) {
        $iid = (int)$row['issue_id'];
        if (!isset($issueTagsMap[$iid])) $issueTagsMap[$iid] = array();
        $issueTagsMap[$iid][] = $row['name'];
    }
    $res->close();
    $mysqli->close();
}
// Attach tags to each issue
foreach ($allIssues as &$issue) {
    $iid = !empty($issue['internal_issue_id']) ? (int)$issue['internal_issue_id'] : null;
    $issue['tags'] = ($iid && isset($issueTagsMap[$iid])) ? $issueTagsMap[$iid] : array();
}
unset($issue);

// Fetch coworkers for each merged issue (if internal_issue_id exists)
$issueCoworkers = [];
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
foreach ($allIssues as $issue) {
    $internal_issue_id = isset($issue['internal_issue_id']) ? $issue['internal_issue_id'] : null;
    $issueCoworkers[$internal_issue_id] = [];
    if (!empty($internal_issue_id)) {
        $stmt = $mysqli->prepare("SELECT c.fullname FROM issue_coworkers ic JOIN coworkers c ON ic.coworker_id = c.id WHERE ic.issue_id = ?");
        $stmt->bind_param("i", $internal_issue_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $issueCoworkers[$internal_issue_id][] = $row['fullname'];
        }
        $stmt->close();
    }
}
$mysqli->close();

// Fetch JIRA details for each merged issue
foreach ($allIssues as &$issue) {
    $jira = null;
    if (!empty($issue['keyname']) && (isset($issue['source_type']) && $issue['source_type'] === 'jira')) {
        $jira = fetchJiraIssue($issue['keyname'], $JIRA_DOMAIN, $JIRA_EMAIL, $JIRA_API_TOKEN);
    }
    $issue['jira_summary'] = isset($jira['summary']) ? $jira['summary'] : (isset($issue['title']) ? $issue['title'] : '[No summary]');
    $issue['jira_status'] = isset($jira['status']) ? $jira['status'] : (isset($issue['status']) && $issue['source_type'] === 'github' ? $issue['status'] : null);
    $issue['jira_updated'] = isset($jira['updated']) ? $jira['updated'] : '';
    $issue['jira_assignee'] = isset($jira['assignee']) ? $jira['assignee'] : '[No assignee]';
    // Unified status for swimlane
    if (isset($issue['source_type']) && $issue['source_type'] === 'github') {
        $issue['unified_status'] = isset($issue['status']) ? $issue['status'] : 'Unknown';
    } else {
        $issue['unified_status'] = isset($issue['jira_status']) ? $issue['jira_status'] : 'Unknown';
    }
}
unset($issue);

// Build a set of valid status IDs for the user
$validStatusIds = array_map(function($s) { return $s['id']; }, $statuses);

// Organize issues by column (internal status), then by swim lane (unified status)
$issuesByColumnAndLane = [];
foreach ($columns as $col) {
    $issuesByColumnAndLane[$col['id']] = [];
}
foreach ($allIssues as $issue) {
    $colId = isset($issue['internal_status_id']) ? $issue['internal_status_id'] : $noStatusId;
    if (empty($issue['internal_status_id']) || !in_array($issue['internal_status_id'], $validStatusIds)) {
        $colId = $noStatusId;
    }
    $lane = isset($issue['unified_status']) ? $issue['unified_status'] : 'Unknown';
    if (!isset($issuesByColumnAndLane[$colId][$lane])) {
        $issuesByColumnAndLane[$colId][$lane] = [];
    }
    $issuesByColumnAndLane[$colId][$lane][] = $issue;
}

// Color palette for columns
$laneColors = [
    'bg-primary', 'bg-success', 'bg-warning', 'bg-info', 'bg-secondary', 'bg-dark', 'bg-light text-dark'
];
?>
<h1 class="mb-4">Kanban Board</h1>
<div class="kanban-scroll" style="overflow-x:auto; white-space:nowrap; padding-bottom:1em;">
  <div class="d-flex flex-row" style="gap:1.5em;">
    <?php foreach ($columns as $i => $col): ?>
      <div class="kanban-col card mb-3" style="min-width:340px; display:inline-block; vertical-align:top;">
        <div class="card-header text-white text-center <?= $laneColors[$i % count($laneColors)] ?>">
          <strong><?= htmlspecialchars($col['name']) ?></strong>
        </div>
        <div class="card-body" style="background:#f8f9fa; min-height:220px;">
          <?php if (!empty($issuesByColumnAndLane[$col['id']])): ?>
            <?php foreach ($issuesByColumnAndLane[$col['id']] as $jiraStatus => $laneIssues): ?>
              <div class="mb-3">
                <div class="swimlane-header mb-2" style="font-weight:bold; color:#333; background:#e9ecef; padding:0.25em 0.5em; border-radius:4px;">
                  <?= htmlspecialchars($jiraStatus) ?>
                </div>
                <?php foreach ($laneIssues as $issue): ?>
                  <div class="card mb-2 shadow-sm" style="font-size:0.97em;">
                    <div class="card-body p-2">
                      <h6 class="card-title mb-1" style="font-size:1em; word-break:break-word; white-space:normal;">
                        <a href="view.php?key=<?= rawurlencode(isset($issue['keyname']) ? $issue['keyname'] : '') ?>" style="text-decoration:none; font-weight:bold; color:inherit;">
                          <?= htmlspecialchars(isset($issue['keyname']) ? $issue['keyname'] : '[No JIRA key]') ?>
                        </a>: <?= htmlspecialchars(isset($issue['jira_summary']) ? $issue['jira_summary'] : '[No summary]') ?>
                      </h6>
                      <div class="mb-1">
                        <span class="badge bg-info text-dark">
                          <?php if (isset($issue['source_type']) && $issue['source_type'] === 'github'): ?>
                            GitHub Status: <?= htmlspecialchars(isset($issue['jira_status']) ? $issue['jira_status'] : '[No GitHub Status]') ?>
                          <?php else: ?>
                            JIRA Status: <?= htmlspecialchars(isset($issue['jira_status']) ? $issue['jira_status'] : '[No JIRA Status]') ?>
                          <?php endif; ?>
                        </span>
                        <span class="badge bg-light text-dark">Assignee: <?= htmlspecialchars(isset($issue['jira_assignee']) ? $issue['jira_assignee'] : '[No assignee]') ?></span>
                      </div>
                      <div class="mb-1">
                        <?php if (!empty($issueCoworkers[$issue['internal_issue_id']])): ?>
                          <?php foreach ($issueCoworkers[$issue['internal_issue_id']] as $coworker): ?>
                            <span class="badge bg-secondary text-light"><?= htmlspecialchars($coworker) ?></span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="badge bg-light text-dark">No coworkers</span>
                        <?php endif; ?>
                        <?php if (!empty($issue['tags'])): ?>
                          <?php foreach ($issue['tags'] as $tag): ?>
                            <span class="badge bg-success ms-1">#<?= htmlspecialchars($tag) ?></span>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($issue['notes'])): ?>
                        <div class="mb-1"><span class="badge bg-warning text-dark">Notes</span> <?= nl2br(htmlspecialchars($issue['notes'])) ?></div>
                      <?php endif; ?>
                      <div class="text-muted" style="font-size:0.85em;">Last updated: <?= !empty($issue['jira_updated']) ? date('Y-m-d H:i', strtotime($issue['jira_updated'])) : 'N/A' ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-muted text-center" style="margin-top:2em;">No issues in this column.</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include __DIR__ . "/template/footer.php"; ?>
