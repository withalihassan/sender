<?php
// login.php
require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...)
session_start();

// Simple helper to detect if a stored value looks like a password_hash() output
function looks_like_hash($s) {
    if (!is_string($s) || $s === '') return false;
    // common bcrypt/argon/sha-based hashes start with $... or are long
    return (strpos($s, '$') === 0 && strlen($s) >= 20) || strlen($s) > 60;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both username and password.';
    } else {
        // limit username length to avoid abuse
        if (strlen($username) > 255) {
            $errors[] = 'Username too long.';
        } else {
            // fetch user by username from `users` table
            $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $stored = (string)$row['password'];
                $ok = false;

                // If stored looks like a hash, prefer password_verify()
                if (looks_like_hash($stored) && @password_verify($password, $stored)) {
                    $ok = true;
                } else {
                    // Fallback: constant-time comparison for plaintext storage
                    // Use hash_equals to avoid timing attacks (works when both strings have same length,
                    // but we still use it — if lengths differ it will return false).
                    if (hash_equals($stored, $password)) {
                        $ok = true;
                    }
                }

                if ($ok) {
                    // Successful login
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$row['id'];
                    $_SESSION['username'] = $row['username'];

                    // OPTIONAL: update last_login if you add such column
                    // $upd = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
                    // $upd->execute([':id' => $row['id']]);

                    header('Location: index.php');
                    exit;
                }
            }

            $errors[] = 'Invalid username or password.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px}</style>
</head>
<body>
<div class="container" style="max-width:420px">
  <div class="card card-body">
    <h4 class="mb-3">Sign in to OKx Portal</h4>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input name="username" class="form-control" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required />
      </div>

      <div class="mb-3">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control" required />
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">Login</button>
        <a href="index.php" class="btn btn-secondary">Back</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
