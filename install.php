<?php
require_once __DIR__ . '/config.php';
$pageTitle = "Install & Schema Sync";
require_once __DIR__ . '/template/header.php';

function getCurrentSchema($mysqli) {
    $schema = [];
    $res = $mysqli->query("SHOW TABLES");
    while ($row = $res->fetch_row()) {
        $table = $row[0];
        $schema[$table] = [];
        $cols = $mysqli->query("SHOW COLUMNS FROM `$table`");
        while ($col = $cols->fetch_assoc()) {
            $schema[$table][$col['Field']] = $col;
        }
        $cols->close();
    }
    $res->close();
    return $schema;
}

function getXmlSchema($xmlFile) {
    $xml = simplexml_load_file($xmlFile);
    $schema = [];
    foreach ($xml->table as $table) {
        $tname = (string)$table['name'];
        $schema[$tname] = [];
        foreach ($table->column as $col) {
            $cname = (string)$col['name'];
            $schema[$tname][$cname] = [
                'type' => (string)$col['type'],
                'autoincrement' => ((string)$col['autoincrement']) === 'true',
                'primary' => ((string)$col['primary']) === 'true',
                'notnull' => ((string)$col['notnull']) === 'true',
            ];
        }
    }
    return $schema;
}

function syncSchema($mysqli, $current, $desired) {
    $changes = [];
    foreach ($desired as $table => $cols) {
        if (!isset($current[$table])) {
            // Table missing, create it
            $sql = "CREATE TABLE `$table` (";
            $defs = [];
            $pk = [];
            foreach ($cols as $cname => $cdef) {
                $def = "`$cname` " . $cdef['type'];
                if ($cdef['autoincrement']) $def .= " AUTO_INCREMENT";
                if ($cdef['notnull']) $def .= " NOT NULL";
                $defs[] = $def;
                if ($cdef['primary']) $pk[] = "`$cname`";
            }
            if ($pk) $defs[] = "PRIMARY KEY (" . implode(",", $pk) . ")";
            $sql .= implode(", ", $defs) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $mysqli->query($sql);
            $changes[] = "Created table $table.";
            continue;
        }
        // Table exists, check columns
        foreach ($cols as $cname => $cdef) {
            if (!isset($current[$table][$cname])) {
                // Column missing, add it
                $sql = "ALTER TABLE `$table` ADD COLUMN `$cname` " . $cdef['type'];
                if ($cdef['autoincrement']) $sql .= " AUTO_INCREMENT";
                if ($cdef['notnull']) $sql .= " NOT NULL";
                $mysqli->query($sql);
                $changes[] = "Added column $cname to $table.";
            }
            // Could add more checks for type changes, notnull, etc.
        }
    }
    return $changes;
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    echo "<div class='alert alert-danger'>Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . "</div>";
    require_once __DIR__ . '/template/footer.php';
    exit();
}

$currentSchema = getCurrentSchema($mysqli);
$desiredSchema = getXmlSchema(__DIR__ . '/tables.xml');
$changes = syncSchema($mysqli, $currentSchema, $desiredSchema);

if ($changes) {
    echo "<div class='alert alert-warning'><strong>Schema updated:</strong><ul>";
    foreach ($changes as $change) echo "<li>$change</li>";
    echo "</ul></div>";
} else {
    echo "<div class='alert alert-success'>Database schema is up to date.</div>";
}

require_once __DIR__ . '/template/footer.php';

