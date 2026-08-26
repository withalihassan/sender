<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && (password_verify($password, $user['password']) || $password === $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: index.php');
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-box {
            width: 360px;
            max-width: 92%;
            background: #fff;
            padding: 28px;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .logo {
            text-align: center;
            font-size: 30px;
            font-weight: bold;
            color: #232f3e;
            margin-bottom: 24px;
        }

        .logo span {
            color: #ff9900;
        }

        h2 {
            margin: 0 0 20px;
            color: #111827;
            font-size: 24px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            color: #333;
        }

        input {
            width: 100%;
            padding: 11px;
            margin-bottom: 16px;
            border: 1px solid #a6a6a6;
            border-radius: 4px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #ff9900;
            box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.25);
        }

        button {
            width: 100%;
            padding: 12px;
            border: 0;
            border-radius: 4px;
            background: #ff9900;
            color: #111;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #f3a847;
        }

        .error {
            padding: 10px;
            margin-bottom: 16px;
            color: #842029;
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo">amazon<span>.</span></div>
        <h2>Sign in</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <label>Username</label>
            <input type="text" name="username" required>

            <label>Password</label>
            <input type="password" name="password" required>

            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
