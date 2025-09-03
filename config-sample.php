<?php
// config.php

// Database connection info
$DB_HOST = "";
$DB_PORT = 3306;
$DB_USER = "";
$DB_PASS = "";
$DB_NAME = "";

function getPDO() {
    global $DB_HOST, $DB_PORT, $DB_USER, $DB_PASS, $DB_NAME;
    $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
    return new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

function getUserConfig($user_id, $name) {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT value FROM config WHERE user_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$user_id, $name]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : null;
}
