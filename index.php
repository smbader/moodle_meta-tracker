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
?>
<section class="section">
    <div class="container">
        <div class="row g-4">
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header text-center bg-primary text-white">Saved Tracker Items</div>
                    <div class="card-body">
                        <?php
                        $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
                        if ($mysqli->connect_errno) {
                            echo "<div class='alert alert-danger'>Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . "</div>";
                        } else {
                            if (!$JIRA_DOMAIN || !$JIRA_EMAIL || !$JIRA_API_TOKEN) {
                                echo "<div class='alert alert-warning'>JIRA credentials are not set. Please <a href='credentials.php'>set your JIRA credentials</a> to view tracker items.</div>";
                            } else {
                                $stmt = $mysqli->prepare("SELECT keyname FROM saved_issues WHERE user_id = ?");
                                $stmt->bind_param("i", $user_id);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($result && $result->num_rows > 0) {
                                    echo "<table class='table table-striped table-bordered'><thead><tr><th>Issue Key</th><th>Status</th><th>Latest Comment</th></tr></thead><tbody>";
                                    while ($row = $result->fetch_assoc()) {
                                        $issueKey = htmlspecialchars($row['keyname']);
                                        $jiraUrl = $JIRA_DOMAIN . '/browse/' . urlencode($row['keyname']);
                                        // Get JIRA status
                                        $ch = curl_init();
                                        curl_setopt_array($ch, [
                                            CURLOPT_URL => $JIRA_DOMAIN . "/rest/api/3/issue/" . $issueKey . "?fields=status",
                                            CURLOPT_RETURNTRANSFER => true,
                                            CURLOPT_HTTPHEADER => [
                                                "Authorization: Basic " . base64_encode("$JIRA_EMAIL:$JIRA_API_TOKEN"),
                                                "Accept: application/json"
                                            ]
                                        ]);
                                        $issueResponse = curl_exec($ch);
                                        $status = "Unknown";
                                        if (!curl_errno($ch)) {
                                            $data = json_decode($issueResponse, true);
                                            if (isset($data['fields']['status']['name'])) {
                                                $status = htmlspecialchars($data['fields']['status']['name']);
                                            }
                                        }
                                        curl_close($ch);
                                        // Get latest comment
                                        $ch = curl_init();
                                        curl_setopt_array($ch, [
                                            CURLOPT_URL => $JIRA_DOMAIN . "/rest/api/3/issue/" . $issueKey . "/comment?orderBy=created&maxResults=1",
                                            CURLOPT_RETURNTRANSFER => true,
                                            CURLOPT_HTTPHEADER => [
                                                "Authorization: Basic " . base64_encode("$JIRA_EMAIL:$JIRA_API_TOKEN"),
                                                "Accept: application/json"
                                            ]
                                        ]);
                                        $commentResponse = curl_exec($ch);
                                        $latestComment = "No comments.";
                                        if (!curl_errno($ch)) {
                                            $commentData = json_decode($commentResponse, true);
                                            if (!empty($commentData['comments'])) {
                                                $comment = $commentData['comments'][0];
                                                $author = htmlspecialchars($comment['author']['displayName']);
                                                // Convert JIRA date to local date
                                                $createdRaw = $comment['created'];
                                                $createdDate = date('Y-m-d H:i:s', strtotime($createdRaw));
                                                $body = htmlspecialchars(isset($comment['body']['content'][0]['content'][0]['text']) ? $comment['body']['content'][0]['content'][0]['text'] : '');
                                                $latestComment = "<strong>$author</strong> ($createdDate): $body";
                                            }
                                        }
                                        curl_close($ch);
                                        echo "<tr><td style='white-space:nowrap;'><a href='$jiraUrl' target='_blank'><strong>$issueKey</strong></a></td><td>$status</td><td>$latestComment</td></tr>";
                                    }
                                    echo "</tbody></table>";
                                } else {
                                    echo "<div class='alert alert-info'>No saved tracker items found.</div>";
                                }
                                $stmt->close();
                            }
                            $mysqli->close();
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/template/footer.php'; ?>
