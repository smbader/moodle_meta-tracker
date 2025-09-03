<?php
// login.php
session_start();
require_once 'config.php';

$pageTitle = 'Login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $errors = [];

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, password_hash, fullname FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        $loginSuccess = true;
    } else {
        $errors[] = 'Invalid email or password.';
    }
}
include 'template/header.php';
?>
<main id="main-content" tabindex="-1">
<div class="container d-flex justify-content-center align-items-center" style="min-height: 80vh;">
  <div class="card shadow-sm p-4" style="min-width: 350px; max-width: 400px; width: 100%;">
    <h2 class="mb-4 text-center">Login</h2>
    <?php if (!empty($loginSuccess)) { ?>
      <div class="alert alert-success" role="alert">
        Welcome, <?php echo htmlspecialchars($_SESSION['fullname'] ?? 'user'); ?>
        <br><a href="index.php">Go to main page</a>
      </div>
    <?php } ?>
    <?php if (!empty($errors)) { ?>
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
          <?php foreach ($errors as $error) { echo '<li>' . htmlspecialchars($error) . '</li>'; } ?>
        </ul>
      </div>
    <?php } ?>
    <?php if (empty($loginSuccess)) { ?>
    <form method="post">
      <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control" id="email" name="email" required autofocus>
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>
    <div class="mt-3 text-center">
      <a href="register.php">Don't have an account? Register</a>
    </div>
    <?php } ?>
  </div>
</div>
</main>
<?php include 'template/footer.php'; ?>
