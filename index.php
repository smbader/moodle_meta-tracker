<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$pageTitle = "Dashboard";
require_once __DIR__ . '/template/header.php';
require_once __DIR__ . '/config.php';
$user_id = $_SESSION['user_id'];
$JIRA_DOMAIN = getUserConfig($user_id, 'jira_domain');
$JIRA_EMAIL = getUserConfig($user_id, 'jira_email');
$JIRA_API_TOKEN = getUserConfig($user_id, 'jira_token');

function fetchJiraIssueDashboard($keyname, $jiraDomain, $jiraEmail, $jiraToken) {
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
    return [
        'summary' => $summary,
        'status' => $status,
        'updated' => $updated
    ];
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger'>Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . "</div>";
    require_once __DIR__ . '/template/footer.php';
    exit;
}

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
$stmt = $mysqli->prepare("SELECT * FROM issues");
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
        $merged = array_merge($internalIssues[$keyname], $saved);
        $merged['internal_issue_id'] = $internalIssues[$keyname]['id'];
    } else {
        $merged['internal_issue_id'] = null;
    }
    $allIssues[] = $merged;
}

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
    $idsStr = implode(',', $internalIssueIds);
    $sql = "SELECT it.issue_id, t.name FROM issue_tags it JOIN tags t ON it.tag_id = t.id WHERE it.issue_id IN ($idsStr) ORDER BY t.name ASC";
    $res = $mysqli->query($sql);
    while ($row = $res->fetch_assoc()) {
        $iid = (int)$row['issue_id'];
        if (!isset($issueTagsMap[$iid])) $issueTagsMap[$iid] = array();
        $issueTagsMap[$iid][] = $row['name'];
    }
    if ($res) $res->close();
}
// Attach tags to each issue
foreach ($allIssues as &$issue) {
    $iid = !empty($issue['internal_issue_id']) ? (int)$issue['internal_issue_id'] : null;
    $issue['tags'] = ($iid && isset($issueTagsMap[$iid])) ? $issueTagsMap[$iid] : array();
}
unset($issue);

// Fetch coworkers for each merged issue (if internal_issue_id exists)
$issueCoworkers = [];
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

// Fetch JIRA/GitHub details and build display data
foreach ($allIssues as &$issue) {
    $lastUpdate = null;
    $status = null;
    $title = isset($issue['title']) ? $issue['title'] : '';
    if (isset($issue['source_type']) && $issue['source_type'] === 'jira') {
        $jira = fetchJiraIssueDashboard($issue['keyname'], $JIRA_DOMAIN, $JIRA_EMAIL, $JIRA_API_TOKEN);
        $title = isset($jira['summary']) ? $jira['summary'] : $title;
        $status = isset($jira['status']) ? $jira['status'] : (isset($issue['status']) ? $issue['status'] : 'Unknown');
        $lastUpdate = isset($jira['updated']) ? $jira['updated'] : null;
    } elseif (isset($issue['source_type']) && $issue['source_type'] === 'github') {
        // For GitHub, use status and updated from saved_issues
        $status = isset($issue['status']) ? $issue['status'] : 'Unknown';
        $lastUpdate = isset($issue['updated_at']) ? $issue['updated_at'] : null;
    } else {
        $status = isset($issue['status']) ? $issue['status'] : 'Unknown';
        $lastUpdate = isset($issue['updated_at']) ? $issue['updated_at'] : null;
    }
    $issue['display_title'] = $title;
    $issue['display_status'] = $status;
    $issue['display_last_update'] = $lastUpdate;
}
unset($issue);

// Sort by last update descending
usort($allIssues, function($a, $b) {
    $aDate = !empty($a['display_last_update']) ? strtotime($a['display_last_update']) : 0;
    $bDate = !empty($b['display_last_update']) ? strtotime($b['display_last_update']) : 0;
    return $bDate <=> $aDate;
});
$mysqli->close();
?>
<section class="section">
    <div class="container">
        <div class="row g-4">
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header text-center bg-primary text-white">Saved Issues Overview</div>
                    <div class="card-body">
                        <table class="table table-striped table-bordered align-middle">
                            <thead>
                                <tr>
                                    <th>Issue Key</th>
                                    <th>Title</th>
                                    <th>Tags</th>
                                    <th>Assigned Workers</th>
                                    <th>Last Update</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($allIssues)): ?>
                                    <?php foreach ($allIssues as $issue): ?>
                                        <tr>
                                            <td style="white-space:nowrap;">
                                                <?php if (isset($issue['source_type']) && $issue['source_type'] === 'jira'): ?>
                                                    <a href="<?= htmlspecialchars($JIRA_DOMAIN . '/browse/' . urlencode($issue['keyname'])) ?>" target="_blank"><strong><?= htmlspecialchars($issue['keyname']) ?></strong></a>
                                                <?php elseif (isset($issue['source_type']) && $issue['source_type'] === 'github' && preg_match('#^([\w\-\.]+)/([\w\-\.]+)\#(\d+)$#', $issue['keyname'], $m)): ?>
                                                    <a href="https://github.com/<?= htmlspecialchars($m[1]) ?>/<?= htmlspecialchars($m[2]) ?>/issues/<?= htmlspecialchars($m[3]) ?>" target="_blank"><strong><?= htmlspecialchars($issue['keyname']) ?></strong></a>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($issue['keyname']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($issue['display_title']) ?></td>
                                            <td>
                                                <?php if (!empty($issue['tags'])): ?>
                                                    <?php foreach ($issue['tags'] as $tag): ?>
                                                        <span class="badge bg-success">#<?= htmlspecialchars($tag) ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php $iid = isset($issue['internal_issue_id']) ? $issue['internal_issue_id'] : null; ?>
                                                <?php if (!empty($iid) && !empty($issueCoworkers[$iid])): ?>
                                                    <?php foreach ($issueCoworkers[$iid] as $coworker): ?>
                                                        <span class="badge bg-secondary"><?= htmlspecialchars($coworker) ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= !empty($issue['display_last_update']) ? date('Y-m-d H:i', strtotime($issue['display_last_update'])) : 'N/A' ?></td>
                                            <td><?= htmlspecialchars($issue['display_status']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted">No saved issues found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/template/footer.php'; ?>
