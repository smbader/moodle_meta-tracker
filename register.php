<?php
// register.php
session_start();
require_once 'config.php';

$pageTitle = 'Register';

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
include 'template/header.php';
?>
<div class="container d-flex justify-content-center align-items-center" style="min-height: 80vh;">
  <div class="card shadow-sm p-4" style="min-width: 350px; max-width: 400px; width: 100%;">
    <h2 class="mb-4 text-center">Register</h2>
    <?php if (!empty($errors)) { ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $error) { echo '<li>' . htmlspecialchars($error) . '</li>'; } ?>
        </ul>
      </div>
    <?php } ?>
    <form method="post">
      <div class="mb-3">
        <label for="fullname" class="form-label">Full Name</label>
        <input type="text" class="form-control" id="fullname" name="fullname" required>
      </div>
      <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control" id="email" name="email" required>
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">Register</button>
    </form>
    <div class="mt-3 text-center">
      <a href="login.php">Already have an account? Login</a>
    </div>
  </div>
</div>
<?php include 'template/footer.php'; ?>
