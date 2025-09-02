<?php
$pageTitle = "LAMP STACK";
require_once __DIR__ . '/template/header.php';
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>LAMP STACK</title>
        <link rel="shortcut icon" href="/assets/images/favicon.svg" type="image/svg+xml">
        <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    </head>
    <body>
        <section class="hero is-medium is-info is-bold">
            <div class="hero-body">
                <div class="container has-text-centered">
                    <h1 class="title">
                        LAMP STACK - Steve
                    </h1>
                    <h2 class="subtitle">
                        Your local development environment
                    </h2>
                </div>
            </div>
        </section>
        <section class="section">
            <div class="container">
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card h-100">
                            <div class="card-header text-center bg-primary text-white">Saved Tracker Items</div>
                            <div class="card-body">
                                <?php
                                require_once __DIR__ . '/config.php';
                                $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
                                if ($mysqli->connect_errno) {
                                    echo "<div class='alert alert-danger'>Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . "</div>";
                                } else {
                                    $result = $mysqli->query("SELECT keyname FROM saved_issues");
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
                                                    $body = htmlspecialchars($comment['body']['content'][0]['content'][0]['text'] ?? '');
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
                                    $mysqli->close();
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <footer class="footer">
            <div class="content has-text-centered">
                <p>
                    <strong><a href="https://www.sprintcube.com" target="_blank">SprintCube</a></strong><br>
                    The source code is released under the <a href="https://github.com/sprintcube/docker-compose-lamp/blob/master/LICENSE" target="_blank">MIT license</a>.
                </p>
            </div>
        </footer>
    </body>
</html>
<?php require_once __DIR__ . '/template/footer.php'; ?>
