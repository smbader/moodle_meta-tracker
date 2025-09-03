<?php
// logout.php
session_start();
session_unset();
session_destroy();

include 'template/header.php';
?>
<main id="main-content" tabindex="-1">
<div class="container d-flex justify-content-center align-items-center" style="min-height: 80vh;">
  <div class="card shadow-sm p-4" style="min-width: 350px; max-width: 400px; width: 100%;">
    <div class="alert alert-success text-center" role="alert">
      You have been logged out
      <br><a href="login.php">Go to login</a>
    </div>
  </div>
</div>
</main>
<?php include 'template/footer.php'; ?>
