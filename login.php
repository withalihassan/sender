<?php
session_start();

// If already logged in, redirect based on account type
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['type'] === 'provider') {
        header("Location: provider/index.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

include('db.php');
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Query the database for matching user credentials
    $stmt = $pdo->prepare("SELECT id, type FROM users WHERE username = ? AND password = ?");
    $stmt->execute([$username, $password]);
    
    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        // Login success: set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['type'] = $user['type'];
        
        // Redirect based on account type
        if ($user['type'] === 'provider') {
            header("Location: provider/index.php");
        } else { // assume consumer
            header("Location: index.php");
        }
        exit;
    } else {
        $message = "Invalid login credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | Sender Console</title>
  <style>
    :root {
      --text: #f7fbff;
      --muted: #9fb1c7;
      --line: rgba(255, 255, 255, 0.16);
      --accent: #3ee6b5;
      --accent-2: #7c8cff;
      --shadow: 0 22px 60px rgba(0, 0, 0, 0.32);
    }

    * {
      box-sizing: border-box;
    }

    html {
      min-height: 100%;
    }

    body {
      min-height: 100vh;
      margin: 0;
      color: var(--text);
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at 50% 15%, rgba(62, 230, 181, 0.16), transparent 28%),
        radial-gradient(circle at 85% 85%, rgba(124, 140, 255, 0.14), transparent 26%),
        linear-gradient(135deg, #08111f 0%, #101a2b 48%, #121422 100%);
      overflow-x: hidden;
    }

    .login-shell {
      width: min(500px, calc(100% - 32px));
      min-height: 100vh;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 0;
      position: relative;
      z-index: 1;
    }

    .brand-mark {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 30px;
      color: var(--text);
      text-decoration: none;
      font-weight: 800;
      letter-spacing: 0;
    }

    .brand-icon {
      width: 44px;
      height: 44px;
      display: grid;
      place-items: center;
      border-radius: 14px;
      background: linear-gradient(135deg, var(--accent), var(--accent-2));
      color: #06101e;
      box-shadow: 0 12px 34px rgba(62, 230, 181, 0.2);
    }

    .brand-icon svg {
      width: 24px;
      height: 24px;
    }

    .login-card {
      width: 100%;
      padding: 34px;
      border: 1px solid var(--line);
      border-radius: 28px;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.13), rgba(255, 255, 255, 0.075));
      box-shadow: var(--shadow);
      backdrop-filter: blur(22px);
    }

    .eyebrow {
      margin: 0 0 10px;
      color: var(--accent);
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }

    h1 {
      margin: 0;
      font-size: clamp(2rem, 4vw, 3.4rem);
      line-height: 1.02;
      letter-spacing: 0;
    }

    .login-card h1 {
      font-size: clamp(2rem, 6vw, 2.7rem);
    }

    .intro {
      margin: 14px 0 28px;
      color: var(--muted);
      line-height: 1.7;
      font-size: 1rem;
    }

    .alert {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      margin-bottom: 22px;
      padding: 14px 16px;
      border: 1px solid rgba(255, 111, 142, 0.38);
      border-radius: 16px;
      color: #ffd8e1;
      background: rgba(255, 111, 142, 0.12);
      font-weight: 700;
    }

    .alert svg {
      width: 20px;
      min-width: 20px;
      margin-top: 1px;
    }

    .form-group {
      margin-bottom: 18px;
    }

    label {
      display: block;
      margin-bottom: 9px;
      color: #dfeaff;
      font-size: 0.92rem;
      font-weight: 750;
    }

    .input-wrap {
      position: relative;
    }

    .input-icon {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      width: 20px;
      height: 20px;
      color: #8fa4bf;
      pointer-events: none;
    }

    .form-control {
      width: 100%;
      height: 54px;
      padding: 0 16px 0 48px;
      border: 1px solid rgba(255, 255, 255, 0.14);
      border-radius: 16px;
      outline: none;
      color: var(--text);
      background: rgba(5, 13, 25, 0.58);
      font: inherit;
      transition: border-color 180ms ease, box-shadow 180ms ease, background 180ms ease;
    }

    .form-control::placeholder {
      color: #7087a4;
    }

    .form-control:focus {
      border-color: rgba(62, 230, 181, 0.82);
      background: rgba(5, 13, 25, 0.76);
      box-shadow: 0 0 0 4px rgba(62, 230, 181, 0.13);
    }

    .password-field .form-control {
      padding-right: 56px;
    }

    .toggle-password {
      position: absolute;
      right: 8px;
      top: 50%;
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 12px;
      color: #d9e6f7;
      background: transparent;
      cursor: pointer;
      transform: translateY(-50%);
      transition: background 180ms ease, color 180ms ease;
    }

    .toggle-password:hover,
    .toggle-password:focus-visible {
      color: var(--accent);
      background: rgba(255, 255, 255, 0.08);
      outline: none;
    }

    .toggle-password svg {
      width: 20px;
      height: 20px;
    }

    .submit-button {
      width: 100%;
      min-height: 56px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-top: 8px;
      border: 0;
      border-radius: 16px;
      color: #03131d;
      background: linear-gradient(135deg, var(--accent), #8df7d6);
      font: inherit;
      font-weight: 900;
      cursor: pointer;
      box-shadow: 0 16px 36px rgba(62, 230, 181, 0.2);
      transition: transform 180ms ease, box-shadow 180ms ease, filter 180ms ease;
    }

    .submit-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 20px 44px rgba(62, 230, 181, 0.26);
    }

    .submit-button:focus-visible {
      outline: 4px solid rgba(62, 230, 181, 0.25);
      outline-offset: 3px;
    }

    .submit-button.is-loading {
      filter: saturate(0.9);
      cursor: wait;
    }

    .button-icon {
      width: 20px;
      height: 20px;
    }

    .trust-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-top: 24px;
    }

    .trust-pill {
      min-height: 72px;
      padding: 12px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.055);
    }

    .trust-pill strong {
      display: block;
      margin-bottom: 4px;
      font-size: 0.9rem;
    }

    .trust-pill span {
      color: var(--muted);
      font-size: 0.76rem;
      line-height: 1.35;
    }

    @media (max-width: 640px) {
      .login-shell {
        width: min(100% - 24px, 500px);
        padding: 24px 0;
      }

      .login-card {
        padding: 24px;
        border-radius: 24px;
      }

      .brand-mark {
        margin-bottom: 22px;
      }

      .trust-row {
        grid-template-columns: 1fr;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      *,
      *::before,
      *::after {
        scroll-behavior: auto !important;
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
    }
  </style>
</head>
<body>
  <main class="login-shell">
    <section class="login-card" aria-labelledby="login-title">
      <a class="brand-mark" href="login.php" aria-label="Sender Console login">
        <span class="brand-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none">
            <path d="M4 12.5 20 4l-4.5 16-3.2-6.2L4 12.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
            <path d="m12.3 13.8 3.2-3.4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </span>
        <span>Sender Console</span>
      </a>

      <p class="eyebrow">Secure access</p>
      <h1 id="login-title">Welcome back.</h1>
      <p class="intro">Sign in to manage accounts, sending workflows, and daily operations from one focused console.</p>

      <?php if ($message): ?>
        <div class="alert" role="alert">
          <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 9v4m0 4h.01M10.3 4.7 2.9 17.5A2 2 0 0 0 4.6 20h14.8a2 2 0 0 0 1.7-2.5L13.7 4.7a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="login.php" id="loginForm">
        <div class="form-group">
          <label for="username">Username</label>
          <div class="input-wrap">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="text" name="username" id="username" class="form-control" placeholder="Enter username" autocomplete="username" required>
          </div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap password-field">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="password" name="password" id="password" class="form-control" placeholder="Enter password" autocomplete="current-password" required>
            <button class="toggle-password" type="button" aria-label="Show password" aria-controls="password" title="Show password">
              <svg class="eye-open" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="2"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="submit-button" id="loginButton">
          <span>Login</span>
          <svg class="button-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </form>

      <div class="trust-row" aria-label="Console highlights">
        <div class="trust-pill">
          <strong>Fast</strong>
          <span>Lightweight page with no extra framework.</span>
        </div>
        <div class="trust-pill">
          <strong>Focused</strong>
          <span>Built for daily account operations.</span>
        </div>
        <div class="trust-pill">
          <strong>Responsive</strong>
          <span>Clean on desktop and mobile screens.</span>
        </div>
      </div>
    </section>

  </main>

  <script>
    (function () {
      var toggle = document.querySelector(".toggle-password");
      var password = document.getElementById("password");
      if (toggle && password) {
        toggle.addEventListener("click", function () {
          var isHidden = password.type === "password";
          password.type = isHidden ? "text" : "password";
          toggle.setAttribute("aria-label", isHidden ? "Hide password" : "Show password");
          toggle.setAttribute("title", isHidden ? "Hide password" : "Show password");
        });
      }

      var form = document.getElementById("loginForm");
      var button = document.getElementById("loginButton");
      if (form && button) {
        form.addEventListener("submit", function () {
          button.classList.add("is-loading");
          button.querySelector("span").textContent = "Signing in...";
        });
      }
    })();
  </script>
</body>
</html>
