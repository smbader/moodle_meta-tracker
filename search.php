<?php
require_once __DIR__ . '/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Use getUserConfig to fetch JIRA credentials for the logged-in user
$user_id = $_SESSION['user_id'];
$jiraDomain = getUserConfig($user_id, 'jira_domain');
$email = getUserConfig($user_id, 'jira_email');
$apiToken = getUserConfig($user_id, 'jira_token');

// Fetch projects
$ch = curl_init();
curl_setopt_array($ch, [
        CURLOPT_URL => $jiraDomain . "/rest/api/3/project/search",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . base64_encode("$email:$apiToken"),
                "Accept: application/json"
        ]
]);
$projectResponse = curl_exec($ch);
$projects = [];
if (!curl_errno($ch)) {
    $data = json_decode($projectResponse, true);
    if (!empty($data['values'])) {
        foreach ($data['values'] as $project) {
            $projects[] = [
                    'key' => $project['key'],
                    'name' => $project['name']
            ];
        }
    }
}
curl_close($ch);

// Fetch issue statuses
$ch = curl_init();
curl_setopt_array($ch, [
        CURLOPT_URL => $jiraDomain . "/rest/api/3/status",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . base64_encode("$email:$apiToken"),
                "Accept: application/json"
        ]
]);
$statusResponse = curl_exec($ch);
$statuses = [];
if (!curl_errno($ch)) {
    $data = json_decode($statusResponse, true);
    if (!empty($data)) {
        foreach ($data as $status) {
            $statuses[] = [
                    'name' => $status['name']
            ];
        }
    }
}
curl_close($ch);

// Pagination
$maxResults = 50;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$startAt = ($page - 1) * $maxResults;

// Fetch custom fields for dropdown if a project is selected
$customFields = [];
if (!empty($_GET['project'])) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $jiraDomain . "/rest/api/3/field",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic " . base64_encode("$email:$apiToken"),
            "Accept: application/json"
        ]
    ]);
    $fieldsResponse = curl_exec($ch);
    if (!curl_errno($ch)) {
        $fieldsData = json_decode($fieldsResponse, true);
        foreach ($fieldsData as $field) {
            // Only show custom fields
            if (isset($field['custom']) && $field['custom'] && strpos($field['id'], 'customfield_') === 0) {
                $customFields[$field['id']] = $field['name'];
            }
        }
    }
    curl_close($ch);
}

// Find custom field IDs for required fields if a project is selected
$requiredCustomFields = [
    'Pull  from Repository' => null,
    'Pull Main Diff URL' => null,
    'Pull Main Branch' => null
];
if (!empty($_GET['project'])) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $jiraDomain . "/rest/api/3/field",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic " . base64_encode("$email:$apiToken"),
            "Accept: application/json"
        ]
    ]);
    $fieldsResponse = curl_exec($ch);
    if (!curl_errno($ch)) {
        $fieldsData = json_decode($fieldsResponse, true);
        foreach ($fieldsData as $field) {
            if (isset($field['custom']) && $field['custom'] && isset($field['name'])) {
                foreach ($requiredCustomFields as $name => $id) {
                    if (strtolower($field['name']) === strtolower($name)) {
                        $requiredCustomFields[$name] = $field['id'];
                    }
                }
            }
        }
    }
    curl_close($ch);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>JIRA Issue Search</title>
</head>
<body>
<?php
$pageTitle = "JIRA Issue Search";
require_once __DIR__ . '/template/header.php';
?>
<div class="row justify-content-center mb-4">
  <div class="col-md-8">
    <form method="get" class="card card-body shadow-sm mb-4">
      <div class="row g-3 align-items-center">
        <div class="col-md-4">
          <label for="project" class="form-label">Project</label>
          <select name="project" id="project" class="form-select" required onchange="this.form.submit()">
            <option value="">Select a project</option>
            <?php foreach ($projects as $project): ?>
              <option value="<?php echo htmlspecialchars($project['key']); ?>"
                <?php if (isset($_GET['project']) && $_GET['project'] === $project['key']) echo 'selected'; ?>>
                <?php echo htmlspecialchars($project['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label for="keywords" class="form-label">Search Keywords</label>
          <input type="text" name="keywords" id="keywords" class="form-control" required value="<?php echo isset($_GET['keywords']) ? htmlspecialchars($_GET['keywords']) : ''; ?>">
        </div>
        <div class="col-md-3">
          <label for="status" class="form-label">Issue State</label>
          <select name="status" id="status" class="form-select">
            <option value="">Any</option>
            <?php foreach ($statuses as $status): ?>
              <option value="<?php echo htmlspecialchars($status['name']); ?>"
                <?php if (isset($_GET['status']) && $_GET['status'] === $status['name']) echo 'selected'; ?>>
                <?php echo htmlspecialchars($status['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <input type="hidden" name="page" value="1">
          <button type="submit" class="btn btn-primary w-100">Search</button>
        </div>
      </div>
      <div class="row mt-3">
        <div class="col-md-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="coworkers" id="coworkers" value="1" <?php if (isset($_GET['coworkers']) && $_GET['coworkers'] == '1') echo 'checked'; ?>>
            <label class="form-check-label" for="coworkers">Coworkers</label>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
// Handle form submission for saving selected issues
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $selected = isset($_POST['selected_issues']) ? $_POST['selected_issues'] : [];
    $mysqli = new mysqli(isset($DB_HOST) ? $DB_HOST : 'localhost', isset($DB_USER) ? $DB_USER : '', isset($DB_PASS) ? $DB_PASS : '', isset($DB_NAME) ? $DB_NAME : '', isset($DB_PORT) ? $DB_PORT : 3306);
    if ($mysqli->connect_errno) {
        echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
        exit();
    }
    foreach ($selected as $keyname) {
        $pull_from_repository = isset($_POST['pull_from_repository'][$keyname]) ? $_POST['pull_from_repository'][$keyname] : null;
        $pull_main_diff_url = isset($_POST['pull_main_diff_url'][$keyname]) ? $_POST['pull_main_diff_url'][$keyname] : null;
        $pull_main_branch = isset($_POST['pull_main_branch'][$keyname]) ? $_POST['pull_main_branch'][$keyname] : null;
        $stmt = $mysqli->prepare("INSERT INTO saved_issues (keyname, pull_from_repository, pull_main_diff_url, pull_main_branch) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $keyname, $pull_from_repository, $pull_main_diff_url, $pull_main_branch);
        $stmt->execute();
        $stmt->close();
    }
    $mysqli->close();
    echo "Selected issues saved!";
}

// Show search results with checkboxes
if (!empty($_GET['keywords']) && !empty($_GET['project'])) {
    $keywords = htmlspecialchars($_GET['keywords']);
    $projectKey = htmlspecialchars($_GET['project']);
    $status = !empty($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
    $jql = "project = \"$projectKey\" AND textfields ~ \"$keywords*\"";
    if ($status) {
        $jql .= " AND status = \"$status\"";
    }
    // Coworkers filter
    if (isset($_GET['coworkers']) && $_GET['coworkers'] == '1') {
        $mysqli = new mysqli(isset($DB_HOST) ? $DB_HOST : 'localhost', isset($DB_USER) ? $DB_USER : '', isset($DB_PASS) ? $DB_PASS : '', isset($DB_NAME) ? $DB_NAME : '', isset($DB_PORT) ? $DB_PORT : 3306);
        // Get current user's email and coworkers' emails
        $coworkerEmails = [$email];
        if (!$mysqli->connect_errno) {
            $res = $mysqli->query("SELECT email FROM coworkers");
            while ($row = $res->fetch_assoc()) {
                $coworkerEmails[] = $row['email'];
            }
            $res->close();
        }
        $mysqli->close();
        if (!empty($coworkerEmails)) {
            $emailList = array_map(function($e) { return '"' . addslashes($e) . '"'; }, $coworkerEmails);
            $emailJQL = implode(",", $emailList);
            $jql .= " AND (assignee IN ($emailJQL) OR reporter IN ($emailJQL) OR voter IN ($emailJQL) OR watcher IN ($emailJQL) OR \"Participants[Participants of an issue]\" IN ($emailJQL))";
        }
    }
    $jql .= " ORDER BY updated DESC";
    $jql = urlencode($jql);
    // Build fields param for JIRA search
    $fieldsParam = ['summary', 'status', 'updated'];
    foreach ($requiredCustomFields as $id) {
        if ($id) $fieldsParam[] = $id;
    }
    $fieldsQuery = !empty($fieldsParam) ? '&fields=' . urlencode(implode(',', $fieldsParam)) : '';
    $ch = curl_init();
    curl_setopt_array($ch, [
            CURLOPT_URL => $jiraDomain . "/rest/api/3/search?jql=" . $jql . "&startAt=$startAt&maxResults=$maxResults" . $fieldsQuery,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                    "Authorization: Basic " . base64_encode("$email:$apiToken"),
                    "Accept: application/json"
            ]
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "<div class='alert alert-danger'>cURL error: " . curl_error($ch) . "</div>";
    } else {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode == 200) {
            $data = json_decode($response, true);
            echo "<h2 class='mb-3'>Search Results:</h2>";
            if (!empty($data['issues'])) {
                echo '<form method="post" action="">';
                echo '<table class="table table-striped table-bordered align-middle" id="results-table">';
                echo '<thead class="table-primary"><tr>';
                echo '<th scope="col"></th>';
                echo '<th scope="col">Issue Key</th>';
                echo '<th scope="col">Title</th>';
                echo '<th scope="col">Status</th>';
                foreach ($requiredCustomFields as $name => $id) {
                    echo '<th scope="col">' . htmlspecialchars($name) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($data['issues'] as $issue) {
                    $key = htmlspecialchars($issue['key']);
                    $jiraUrl = $jiraDomain . '/browse/' . urlencode($issue['key']);
                    $summary = htmlspecialchars($issue['fields']['summary']);
                    $statusName = htmlspecialchars($issue['fields']['status']['name']);
                    $updatedRaw = isset($issue['fields']['updated']) ? $issue['fields']['updated'] : null;
                    $updatedDate = $updatedRaw ? date('Y-m-d H:i:s', strtotime($updatedRaw)) : '';
                    // Get custom field values
                    $cfValues = [];
                    foreach ($requiredCustomFields as $name => $id) {
                        $cfValue = ($id && isset($issue['fields'][$id])) ? $issue['fields'][$id] : '';
                        if (is_array($cfValue) || is_object($cfValue)) {
                            $cfValue = json_encode($cfValue);
                        }
                        $cfValues[$name] = $cfValue;
                    }
                    echo '<tr>';
                    echo '<td><input type="checkbox" name="selected_issues[]" value="' . $key . '"></td>';
                    echo '<td style="white-space:nowrap;"><a href="' . $jiraUrl . '" target="_blank"><strong>' . $key . '</strong></a></td>';
                    echo '<td>' . $summary . '</td>';
                    echo '<td>' . $statusName;
                    if ($updatedDate) {
                        echo '<br><small class="text-muted">Updated: ' . $updatedDate . '</small>';
                    }
                    echo '</td>';
                    foreach ($requiredCustomFields as $name => $id) {
                        $displayValue = $cfValues[$name] !== '' ? $cfValues[$name] : 'N/A';
                        echo '<td>' . htmlspecialchars($displayValue) . '</td>';
                    }
                    // Add hidden fields for saving
                    echo '<input type="hidden" name="pull_from_repository[' . $key . ']" value="' . htmlspecialchars($cfValues['Pull  from Repository']) . '">';
                    echo '<input type="hidden" name="pull_main_diff_url[' . $key . ']" value="' . htmlspecialchars($cfValues['Pull Main Diff URL']) . '">';
                    echo '<input type="hidden" name="pull_main_branch[' . $key . ']" value="' . htmlspecialchars($cfValues['Pull Main Branch']) . '">';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<button type="submit" name="save" class="btn btn-success">Save Selected</button>';
                echo '</form>';
                // Pagination controls
                $total = $data['total'];
                $prevPage = $page > 1 ? $page - 1 : 1;
                $nextPage = ($startAt + $maxResults) < $total ? $page + 1 : $page;
                echo "<div class='mt-3'>";
                if ($page > 1) {
                    echo '<a class="btn btn-outline-primary me-2" href="?project=' . urlencode($projectKey) . '&keywords=' . urlencode($keywords) . '&status=' . urlencode($status) . '&page=' . $prevPage . '">Previous</a>';
                }
                if (($startAt + $maxResults) < $total) {
                    echo '<a class="btn btn-outline-primary" href="?project=' . urlencode($projectKey) . '&keywords=' . urlencode($keywords) . '&status=' . urlencode($status) . '&page=' . $nextPage . '">Next</a>';
                }
                echo "</div>";
            } else {
                echo "<div class='alert alert-info'>No issues found.</div>";
            }
        } else {
            echo "<div class='alert alert-danger'>Request failed. HTTP Code: $httpCode<br>Response: $response</div>";
        }
    }
    curl_close($ch);
}
?>
<?php require_once __DIR__ . '/template/footer.php'; ?>
