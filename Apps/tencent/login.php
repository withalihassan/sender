<?php
session_start();
require 'my_db.php'; // defines $mysqli (mysqli)

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $mysqli->real_escape_string(trim($_POST['username']));
    $password = $_POST['password'];

    // Fetch user
    $sql = "SELECT id, password FROM users WHERE username='" . $username . "' LIMIT 1";
    $result = $mysqli->query($sql);

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if ($password === $user['password']) {
            // Successful login
            $_SESSION['user_id'] = $user['id'];
            header('Location: index.php');
            exit;
        }
    }
    $error = 'Invalid username or password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f0f0f0; display: flex; align-items: center; justify-content: center; height: 100vh; }
    .login-container { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); width: 320px; }
    .login-container h2 { margin-bottom: 1rem; text-align: center; }
    .login-container input { width: 100%; padding: .5rem; margin-bottom: 1rem; border: 1px solid #ccc; border-radius: 4px; }
    .login-container button { width: 100%; padding: .5rem; background: #007BFF; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    .error { color: red; text-align: center; margin-bottom: 1rem; }
  </style>
</head>
<body>
  <div class="login-container">
    <h2>Login</h2>
    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form action="" method="post">
      <input type="text" name="username" placeholder="Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <button type="submit">Log In</button>
    </form>
  </div>
</body>
</html>