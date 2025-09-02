<?php
require_once __DIR__ . '/config.php';

// search.php

// Use config.php variables for JIRA
$jiraDomain = $JIRA_DOMAIN;
$email = $JIRA_EMAIL;
$apiToken = $JIRA_API_TOKEN;

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
?>
<!DOCTYPE html>
<html>
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
          <select name="project" id="project" class="form-select" required>
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
    </form>
  </div>
</div>

<?php
// Handle form submission for saving selected issues
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $selected = isset($_POST['selected_issues']) ? $_POST['selected_issues'] : [];
    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    if ($mysqli->connect_errno) {
        echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
        exit();
    }
    foreach ($selected as $keyname) {
        $stmt = $mysqli->prepare("INSERT INTO saved_issues (keyname) VALUES (?)");
        $stmt->bind_param("s", $keyname);
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
    $jql = "project = \"$projectKey\" AND text ~ \"$keywords\"";
    if ($status) {
        $jql .= " AND status = \"$status\"";
    }
    $jql .= " ORDER BY updated DESC";
    $jql = urlencode($jql);

    $ch = curl_init();
    curl_setopt_array($ch, [
            CURLOPT_URL => $jiraDomain . "/rest/api/3/search?jql=" . $jql . "&startAt=$startAt&maxResults=$maxResults",
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
                echo '</tr></thead><tbody>';
                foreach ($data['issues'] as $issue) {
                    $key = htmlspecialchars($issue['key']);
                    $jiraUrl = $jiraDomain . '/browse/' . urlencode($issue['key']);
                    $summary = htmlspecialchars($issue['fields']['summary']);
                    $statusName = htmlspecialchars($issue['fields']['status']['name']);
                    $updatedRaw = $issue['fields']['updated'] ?? null;
                    $updatedDate = $updatedRaw ? date('Y-m-d H:i:s', strtotime($updatedRaw)) : '';
                    echo '<tr>';
                    echo '<td><input type="checkbox" name="selected_issues[]" value="' . $key . '"></td>';
                    echo '<td style="white-space:nowrap;"><a href="' . $jiraUrl . '" target="_blank"><strong>' . $key . '</strong></a></td>';
                    echo '<td>' . $summary . '</td>';
                    echo '<td>' . $statusName;
                    if ($updatedDate) {
                        echo '<br><small class="text-muted">Updated: ' . $updatedDate . '</small>';
                    }
                    echo '</td>';
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
