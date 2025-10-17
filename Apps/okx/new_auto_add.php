<?php
// auto_add_ac_smtpdev.php
// Usage: ensure db.php sets $pdo = new PDO(...) and session.php provides session info if used.

require_once __DIR__ . '/db.php'; // adjust path if needed
include "../session.php";

$SMTP_BASE = getenv('SMTP_DEV_BASE') ?: 'https://api.smtp.dev';
$SMTP_API_KEY = getenv('SMTP_DEV_API_KEY') ?: 'smtplabs_wu9je5CiV6ezxoELcmk2MYehAzh918uSqK75w7YvjZsRrWRH';

$errors = [];
$created = [];
$failed = [];
$skipped = [];
$success = 0;

// Prefer session user id if available, fall back to GET params
$range_id = isset($_GET['rid']) ? (int)$_GET['rid'] : (isset($_POST['range_id']) ? (int)$_POST['range_id'] : null);
$user_id = isset($_GET['uid']) ? (int)$_GET['uid'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

/**
 * Make API request to smtp.dev
 * returns ['code'=>int, 'body'=>string, 'error'=>string|null]
 */
function api_request($method, $path, $body = null, $extraHeaders = []) {
    global $SMTP_BASE, $SMTP_API_KEY;
    $url = rtrim($SMTP_BASE, '/') . $path;
    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-API-KEY: ' . $SMTP_API_KEY
    ];

    foreach ($extraHeaders as $h) $headers[] = $h;

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => (int)$code, 'body' => $resp === false ? null : $resp, 'error' => $err ? $err : null];
}

/**
 * Choose a domain from /domains
 * returns domain string or throws Exception
 */
function choose_domain() {
    $r = api_request('GET', '/domains?isActive=true&page=1');
    if (!empty($r['error'])) throw new Exception('smtp.dev request error: ' . $r['error']);
    $j = json_decode($r['body'], true);
    if (!is_array($j)) throw new Exception('smtp.dev invalid response (not json)');

    // docs show "member" array; fallback to hydra:member or items or plain array
    $list = $j['member'] ?? $j['hydra:member'] ?? $j['items'] ?? null;
    if (!$list && isset($j[0])) $list = $j;

    if (!is_array($list) || count($list) === 0) {
        throw new Exception('no smtp.dev domains found');
    }

    foreach ($list as $d) {
        if (is_array($d) && !empty($d['domain'])) return $d['domain'];
        if (is_array($d) && !empty($d['name'])) return $d['name'];
    }

    // fallback to first element value
    $first = $list[0];
    if (is_array($first)) {
        if (!empty($first['domain'])) return $first['domain'];
        $vals = array_values($first);
        return (string)($vals[0] ?? reset($first));
    }
    return (string)$first;
}

/**
 * Create account via POST /accounts
 * returns response array from api_request
 */
function create_account_api($address, $password) {
    return api_request('POST', '/accounts', ['address' => $address, 'password' => $password]);
}

/**
 * Check existing account via GET /accounts?address=...
 * returns account info array or null
 */
function get_account_by_address($address) {
    $q = '/accounts?address=' . urlencode($address) . '&page=1';
    $r = api_request('GET', $q);
    if (!empty($r['error'])) return null;
    $j = json_decode($r['body'], true);
    if (!is_array($j)) return null;
    $list = $j['member'] ?? $j['hydra:member'] ?? $j['items'] ?? null;
    if (!$list && isset($j[0])) $list = $j;
    if (is_array($list) && count($list) > 0) {
        return $list[0];
    }
    return null;
}

/**
 * Create or reuse mailbox (using smtp.dev)
 * returns [$address, $password, $generatedBool]
 */
function create_or_get_mailbox_smtpdev($address = null, $password = null, $maxAttempts = 6) {
    $attempt = 0;
    $generatedOverall = false;

    while ($attempt < $maxAttempts) {
        $attempt++;

        // generate domain/local if needed
        if (!$address) {
            try {
                $dom = choose_domain();
            } catch (Exception $e) {
                throw new Exception("choose_domain failed: " . $e->getMessage());
            }
            $local = substr(bin2hex(random_bytes(3)), 0, 6); // 6 hex chars
            $addressCandidate = 'okx-' . $local . '@' . $dom;
            $generatedThis = true;
        } else {
            $addressCandidate = $address;
            $generatedThis = false;
        }

        if (!$password) {
            $passwordCandidate = bin2hex(random_bytes(16));
            $generatedThis = true;
        } else {
            $passwordCandidate = $password;
        }

        $res = create_account_api($addressCandidate, $passwordCandidate);
        $code = (int)$res['code'];
        $body = $res['body'];

        // Success (201/200) - account created
        if (in_array($code, [200, 201, 204])) {
            // created; return
            return [$addressCandidate, $passwordCandidate, ($generatedThis || $generatedOverall)];
        }

        // Conflict / validation error - maybe account exists
        if (in_array($code, [409, 422])) {
            // try to fetch existing account
            $existing = get_account_by_address($addressCandidate);
            if ($existing && !empty($existing['address'])) {
                return [$existing['address'], $passwordCandidate, false];
            } else {
                // if not found, mark generated and retry
                $address = null;
                $password = null;
                $generatedOverall = true;
                usleep(150000);
                continue;
            }
        }

        // Rate limited or server busy
        if ($code === 429) {
            // exponential-ish backoff
            usleep(500000 * $attempt);
            continue;
        }

        // Network/CURL error
        if (!empty($res['error'])) {
            if ($attempt >= $maxAttempts) {
                throw new Exception("Network error on create_account: " . $res['error']);
            }
            usleep(200000);
            continue;
        }

        // Other unexpected response - try to inspect body for details and retry until attempts exhausted
        if ($attempt >= $maxAttempts) {
            $b = is_string($body) ? $body : json_encode($body);
            throw new Exception("Unexpected create_account response (code {$code}): {$b}");
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
                list($email, $email_psw, $generated) = create_or_get_mailbox_smtpdev();
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

            // gentle delay to avoid rate limits
            usleep(200000);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Bulk Add Accounts (smtp.dev)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px}</style>
</head>
<body>
<div class="container" style="max-width:900px">
  <h3 class="mb-3">Bulk Add Accounts — smtp.dev</h3>

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
          <div class="form-text">If an address already exists and the password doesn't match, the script will try to reuse the existing account automatically.</div>
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
      <button class="btn btn-primary" type="submit">Add Bulk Accounts (smtp.dev)</button>
      <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
  </form>
</div>
</body>
</html>
