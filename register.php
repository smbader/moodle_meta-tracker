<?php
// register.php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $errors = [];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (empty($fullname)) {
        $errors[] = 'Full name is required.';
    }

    if (empty($errors)) {
        $pdo = getPDO();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Email already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, fullname) VALUES (?, ?, ?)');
            $stmt->execute([$email, $hash, $fullname]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['fullname'] = $fullname;
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Register</title></head>
<body>
<h2>Register</h2>
<?php if (!empty($errors)) { echo '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>'; } ?>
<form method="post">
    <label>Full Name: <input type="text" name="fullname" required></label><br>
    <label>Email: <input type="email" name="email" required></label><br>
    <label>Password: <input type="password" name="password" required></label><br>
    <button type="submit">Register</button>
</form>
<a href="login.php">Already have an account? Login</a>
</body>
</html>

