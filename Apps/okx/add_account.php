<?php
// add_account.php
require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...);
include "../session.php";

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $email_psw = trim($_POST['email_psw'] ?? '');
  $email_otp = trim($_POST['email_otp'] ?? '');
  $country = trim($_POST['country'] ?? '');
  $num_code = trim($_POST['num_code'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $phone_otp = trim($_POST['phone_otp'] ?? '');
  $account_psw = trim($_POST['account_psw'] ?? '');
  $ac_status = trim($_POST['ac_status'] ?? '');
  $ac_last_used = trim($_POST['ac_last_used'] ?? '');

  if ($email === '') {
    $error = 'Email is required.';
  } else {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO `accounts` 
        (email, email_psw, email_otp, country, num_code, phone, phone_otp, account_psw, ac_status, ac_last_used, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ");
      $stmt->execute([
        $email, $email_psw, $email_otp, $country, $num_code, $phone, $phone_otp, $account_psw, $ac_status, $ac_last_used
      ]);
      header('Location: index.php');
      exit;
    } catch (Exception $e) {
      $error = 'Insert failed: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Add Account</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px}</style>
</head>
<body>
<div class="container" style="max-width:820px">
  <h3 class="mb-3">Add Account</h3>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <form method="post" class="card card-body">
    <div class="row g-2">
      <div class="col-md-6">
        <label class="form-label">Email *</label>
        <input name="email" class="form-control" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>" />
      </div>
      <div class="col-md-6">
        <label class="form-label">Email Password</label>
        <input name="email_psw" class="form-control" value="<?=htmlspecialchars($_POST['email_psw'] ?? '')?>" />
      </div>

      <div class="col-md-4">
        <label class="form-label">Num Code</label>
        <input name="num_code" class="form-control" placeholder="+1" value="<?=htmlspecialchars($_POST['num_code'] ?? '')?>" />
      </div>
      <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input name="phone" class="form-control" value="<?=htmlspecialchars($_POST['phone'] ?? '')?>" />
      </div>
      <div class="col-md-4">
        <label class="form-label">Phone OTP</label>
        <input name="phone_otp" class="form-control" value="<?=htmlspecialchars($_POST['phone_otp'] ?? '')?>" />
      </div>

      <div class="col-md-4">
        <label class="form-label">Country</label>
        <input name="country" class="form-control" value="<?=htmlspecialchars($_POST['country'] ?? '')?>" />
      </div>
      <div class="col-md-4">
        <label class="form-label">Account Password</label>
        <input name="account_psw" class="form-control" value="<?=htmlspecialchars($_POST['account_psw'] ?? '')?>" />
      </div>
      <div class="col-md-4">
        <label class="form-label">Status</label>
        <input name="ac_status" class="form-control" placeholder="active/inactive" value="<?=htmlspecialchars($_POST['ac_status'] ?? '')?>" />
      </div>

      <div class="col-md-6">
        <label class="form-label">Email OTP</label>
        <input name="email_otp" class="form-control" value="<?=htmlspecialchars($_POST['email_otp'] ?? '')?>" />
      </div>
      <div class="col-md-6">
        <label class="form-label">Last Used (YYYY-MM-DD HH:MM:SS)</label>
        <input name="ac_last_used" class="form-control" placeholder="2025-10-15 12:34:56" value="<?=htmlspecialchars($_POST['ac_last_used'] ?? '')?>" />
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Insert</button>
      <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
  </form>
</div>
</body>
</html>
