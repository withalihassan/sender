<?php
// auto_add_ac.php (updated)
// Usage: place in your project, make sure db.php sets $pdo (PDO) and session.php provides session/user if needed.
require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...)
include "../session.php";

$MAILTM_BASE = getenv('MAILTM_BASE') ?: 'https://api.mail.tm';
$errors = [];
$created = [];
$failed = [];
$skipped = [];
$success = 0;

// Prefer session user id if available, fall back to GET params
$range_id = isset($_GET['rid']) ? (int)$_GET['rid'] : (isset($_POST['range_id']) ? (int)$_POST['range_id'] : null);
$user_id = isset($_GET['uid']) ? (int)$_GET['uid'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

function api_request($method, $path, $body = null, $headers = []) {
    global $MAILTM_BASE;
    $url = rtrim($MAILTM_BASE, '/') . $path;
    $ch = curl_init($url);
    $defaultHeaders = ['Accept: application/ld+json', 'Content-Type: application/json'];
    $allHeaders = array_merge($defaultHeaders, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'error' => $err];
}

function choose_domain() {
    $r = api_request('GET', '/domains');
    if ($r['error']) throw new Exception('mail.tm request error: ' . $r['error']);
    $j = json_decode($r['body'], true);
    if (!$j) throw new Exception('mail.tm invalid response (not json)');
    $list = $j['hydra:member'] ?? $j['items'] ?? $j;
    if (!is_array($list) || count($list) === 0) throw new Exception('no mail.tm domains found');

    foreach ($list as $d) {
        if (is_array($d) && !empty($d['domain'])) return $d['domain'];
        if (is_array($d) && !empty($d['name'])) return $d['name'];
    }
    $first = $list[0];
    if (is_array($first)) {
        $vals = array_values($first);
        return $vals[0] ?? reset($first);
    }
    return (string)$first;
}

function create_account($address, $password) {
    $r = api_request('POST', '/accounts', ['address' => $address, 'password' => $password]);
    return $r;
}

function get_token($address, $password) {
    $r = api_request('POST', '/token', ['address' => $address, 'password' => $password]);
    if ($r['error']) return ['ok' => false, 'error' => $r['error'], 'code' => $r['code'], 'body' => $r['body']];
    $j = json_decode($r['body'], true);
    if (is_array($j) && !empty($j['token'])) {
        return ['ok' => true, 'token' => $j['token']];
    }
    if (is_array($j) && !empty($j['accessToken'])) {
        return ['ok' => true, 'token' => $j['accessToken']];
    }
    return ['ok' => false, 'code' => $r['code'], 'body' => $r['body']];
}

function get_me($token) {
    $r = api_request('GET', '/me', null, ['Authorization: Bearer ' . $token]);
    if ($r['error']) return ['ok' => false, 'error' => $r['error']];
    $j = json_decode($r['body'], true);
    if (is_array($j) && !empty($j['id'])) return ['ok' => true, 'me' => $j];
    return ['ok' => false, 'code' => $r['code'], 'body' => $r['body']];
}

function create_or_get_mailbox_verified($address = null, $password = null, $maxAttempts = 6) {
    $attempt = 0;
    $generatedOverall = false;
    while ($attempt < $maxAttempts) {
        $attempt++;
        $generatedThis = false;

        if (!$address) {
            try {
                $dom = choose_domain();
            } catch (Exception $e) {
                throw new Exception("choose_domain failed: " . $e->getMessage());
            }
            $local = substr(bin2hex(random_bytes(3)), 0, 6);
            $addressCandidate = 'okx-' . $local . '@' . $dom;
            $generatedThis = true;
        } else {
            $addressCandidate = $address;
        }

        if (!$password) {
            $passwordCandidate = bin2hex(random_bytes(16));
            $generatedThis = true;
        } else {
            $passwordCandidate = $password;
        }

        $res = create_account($addressCandidate, $passwordCandidate);
        if ($res['error']) {
            if ($attempt >= $maxAttempts) {
                throw new Exception("API network error on create: " . $res['error']);
            } else {
                usleep(200000);
                continue;
            }
        }

        $code = (int)$res['code'];
        $body = $res['body'];

        if (in_array($code, [200, 201])) {
            $tok = get_token($addressCandidate, $passwordCandidate);
            if (!empty($tok['ok']) && !empty($tok['token'])) {
                $me = get_me($tok['token']);
                if (!empty($me['ok'])) {
                    return [$addressCandidate, $passwordCandidate, $generatedThis || $generatedOverall];
                } else {
                    if ($attempt >= $maxAttempts) {
                        throw new Exception("Created account but /me verification failed: " . json_encode($me));
                    }
                    usleep(200000);
                    continue;
                }
            } else {
                if ($attempt >= $maxAttempts) {
                    throw new Exception("Created account but token fetch failed: " . json_encode($tok));
                }
                usleep(200000);
                continue;
            }
        }

        if (in_array($code, [409, 422])) {
            $tok2 = get_token($addressCandidate, $passwordCandidate);
            if (!empty($tok2['ok']) && !empty($tok2['token'])) {
                $me2 = get_me($tok2['token']);
                if (!empty($me2['ok'])) {
                    return [$addressCandidate, $passwordCandidate, false];
                } else {
                    $address = null;
                    $password = null;
                    $generatedOverall = true;
                    usleep(150000);
                    continue;
                }
            } else {
                $address = null;
                $password = null;
                $generatedOverall = true;
                usleep(150000);
                continue;
            }
        }

        if ($attempt >= $maxAttempts) {
            throw new Exception("Unexpected create_account response (code {$code}): {$body}");
        }
        usleep(150000);
    }

    throw new Exception("Failed to create/verify mailbox after {$maxAttempts} attempts");
}

/* ------------------ form processing ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $country = trim($_POST['country'] ?? '');
    $phones_raw = trim($_POST['phones'] ?? '');
    $lines = preg_split("/\r\n|\n|\r/", $phones_raw);
    $phones = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $phones[] = preg_replace('/\s+/', '', $ln);
    }

    if (empty($phones)) {
        $errors[] = "No phone numbers provided.";
    } else {
        // prepare DB statements
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `accounts` WHERE phone = :phone");
        $insertSql = "INSERT INTO `accounts` (by_user, range_id, email, email_psw, email_otp, country, num_code, phone, phone_otp, account_psw, ac_status, ac_last_used, created_at)
                VALUES (:by_user, :range_id, :email, :email_psw, NULL, :country, '', :phone, NULL, '@Okx#1234', NULL, NULL, NOW())";
        $insertStmt = $pdo->prepare($insertSql);

        foreach ($phones as $phone) {
            // check duplicate phone
            try {
                $checkStmt->execute([':phone' => $phone]);
                $cnt = (int)$checkStmt->fetchColumn();
            } catch (Exception $e) {
                $failed[] = ['phone' => $phone, 'reason' => 'DB check failed: ' . $e->getMessage()];
                continue;
            }

            if ($cnt > 0) {
                $skipped[] = ['phone' => $phone, 'reason' => 'duplicate phone'];
                continue;
            }

            try {
                list($email, $email_psw, $generated) = create_or_get_mailbox_verified();
            } catch (Exception $e) {
                $failed[] = ['phone' => $phone, 'reason' => 'mail creation/verify failed: ' . $e->getMessage()];
                continue;
            }

            try {
                $binds = [
                    ':by_user' => $user_id,
                    ':range_id' => $range_id,
                    ':email' => $email,
                    ':email_psw' => $email_psw,
                    ':country' => $country,
                    ':phone' => $phone
                ];
                $insertStmt->execute($binds);
                $success++;
                $created[] = ['phone' => $phone, 'email' => $email];
            } catch (Exception $e) {
                $failed[] = ['phone' => $phone, 'reason' => 'DB insert failed: ' . $e->getMessage(), 'email' => $email];
            }

            usleep(200000);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Bulk Add Accounts (verified)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px}</style>
</head>
<body>
<div class="container" style="max-width:900px">
  <h3 class="mb-3">Bulk Add Accounts — verified</h3>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <strong>Errors:</strong>
      <ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="card mb-3">
      <div class="card-body">
        <p><strong>Inserted:</strong> <?php echo (int)$success; ?> accounts.</p>
        <p>
          <a href="index.php" class="btn btn-sm btn-secondary">Back to list</a>
        </p>
      </div>
    </div>

    <?php if (!empty($created)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Created accounts</h5>
          <ul><?php foreach ($created as $c) echo '<li>'.htmlspecialchars($c['phone']).' → '.htmlspecialchars($c['email']).'</li>'; ?></ul>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($skipped)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Skipped (duplicates)</h5>
          <ul><?php foreach ($skipped as $s) echo '<li>'.htmlspecialchars($s['phone']).' — '.htmlspecialchars($s['reason']).'</li>'; ?></ul>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($failed)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Failed</h5>
          <ul><?php foreach ($failed as $f) {
              echo '<li>'.htmlspecialchars($f['phone'] ?? 'N/A').' — '.htmlspecialchars($f['reason'] ?? 'unknown');
              if (!empty($f['email'])) echo ' (email: '.htmlspecialchars($f['email']).')';
              echo '</li>';
          } ?></ul>
          <div class="form-text">If an address already exists and the password doesn't match, the script will generate a new address automatically.</div>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <form method="post" class="card card-body">
    <div class="mb-3">
      <label class="form-label">Country</label>
      <input name="country" class="form-control" value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>" placeholder="e.g. Pakistan" />
    </div>

    <div class="mb-3">
      <label class="form-label">Phone numbers (one per line)</label>
      <textarea name="phones" rows="10" class="form-control" placeholder="+923001234567<?php echo "\n+923009876543"; ?>"><?php echo htmlspecialchars($_POST['phones'] ?? ''); ?></textarea>
      <div class="form-text">Enter one phone number per line. Duplicate phones will be skipped.</div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary" type="submit">Add Bulk Accounts (verify)</button>
      <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
  </form>
</div>
</body>
</html>
