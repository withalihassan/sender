<?php
// auto_add_ac.php
require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...)
include "../session.php";

$MAILTM_BASE = getenv('MAILTM_BASE') ?: 'https://api.mail.tm';
$errors = [];
$created = [];
$failed = [];
$success = 0;
$CSV_LOG = '/tmp/created_mailtm.csv'; // adjust if needed
$range_id = $_GET["rid"];
$user_id = $_GET["uid"];

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

/**
 * Create account (POST /accounts)
 */
function create_account($address, $password) {
    $r = api_request('POST', '/accounts', ['address' => $address, 'password' => $password]);
    // return whole response to allow caller to inspect code/body
    return $r;
}

/**
 * Attempt to get token for given credentials (POST /token)
 * Returns token string on success, or false on failure.
 */
function get_token($address, $password) {
    $r = api_request('POST', '/token', ['address' => $address, 'password' => $password]);
    if ($r['error']) return ['ok' => false, 'error' => $r['error'], 'code' => $r['code'], 'body' => $r['body']];
    $j = json_decode($r['body'], true);
    if (is_array($j) && !empty($j['token'])) {
        return ['ok' => true, 'token' => $j['token']];
    }
    // some mail.tm APIs return accessToken or similar
    if (is_array($j) && !empty($j['accessToken'])) {
        return ['ok' => true, 'token' => $j['accessToken']];
    }
    return ['ok' => false, 'code' => $r['code'], 'body' => $r['body']];
}

/**
 * Verify token by calling GET /me
 */
function get_me($token) {
    $r = api_request('GET', '/me', null, ['Authorization: Bearer ' . $token]);
    if ($r['error']) return ['ok' => false, 'error' => $r['error']];
    $j = json_decode($r['body'], true);
    if (is_array($j) && !empty($j['id'])) return ['ok' => true, 'me' => $j];
    return ['ok' => false, 'code' => $r['code'], 'body' => $r['body']];
}

/**
 * Attempt to create and verify a working mailbox.
 * Returns array(email, password, generatedFlag) on success or throws Exception on repeated failures.
 *
 * Logic:
 * - Up to $maxAttempts, generate an email+password, try to create account
 * - After create (or if account already exists), attempt to authenticate with /token and /me
 * - If authentication succeeds -> success.
 * - If account exists but auth fails -> discard and generate new email (we cannot change password of an existing account)
 */
function create_or_get_mailbox_verified($address = null, $password = null, $maxAttempts = 6) {
    $attempt = 0;
    $generatedOverall = false;
    while ($attempt < $maxAttempts) {
        $attempt++;
        $generatedThis = false;

        // generate email if not provided
        if (!$address) {
            try {
                $dom = choose_domain();
            } catch (Exception $e) {
                throw new Exception("choose_domain failed: " . $e->getMessage());
            }
            $local = substr(bin2hex(random_bytes(3)), 0, 6); // 6 hex like your python slice
            $addressCandidate = 'okx-' . $local . '@' . $dom;
            $generatedThis = true;
        } else {
            $addressCandidate = $address;
        }

        // generate password if not provided
        if (!$password) {
            $passwordCandidate = bin2hex(random_bytes(16)); // 32 hex chars
            $generatedThis = true;
        } else {
            $passwordCandidate = $password;
        }

        // try create
        $res = create_account($addressCandidate, $passwordCandidate);
        // If cURL/network error
        if ($res['error']) {
            // if transient network error, retry
            if ($attempt >= $maxAttempts) {
                throw new Exception("API network error on create: " . $res['error']);
            } else {
                usleep(200000);
                continue;
            }
        }

        $code = (int)$res['code'];
        $body = $res['body'];

        // If created successfully (201 / 200)
        if (in_array($code, [200, 201])) {
            // now verify login
            $tok = get_token($addressCandidate, $passwordCandidate);
            if (!empty($tok['ok']) && !empty($tok['token'])) {
                $me = get_me($tok['token']);
                if (!empty($me['ok'])) {
                    return [$addressCandidate, $passwordCandidate, $generatedThis || $generatedOverall];
                } else {
                    // unexpected: token returned but /me failed — try again a couple times
                    if ($attempt >= $maxAttempts) {
                        throw new Exception("Created account but /me verification failed: " . json_encode($me));
                    }
                    usleep(200000);
                    continue;
                }
            } else {
                // token failed despite account creation; try again (rare)
                if ($attempt >= $maxAttempts) {
                    throw new Exception("Created account but token fetch failed: " . json_encode($tok));
                }
                usleep(200000);
                continue;
            }
        }

        // If account already exists (common scenario). Many APIs return 422 or 409.
        if (in_array($code, [409, 422])) {
            // Try to login with the password we attempted (maybe the account already exists and our password matches)
            $tok2 = get_token($addressCandidate, $passwordCandidate);
            if (!empty($tok2['ok']) && !empty($tok2['token'])) {
                $me2 = get_me($tok2['token']);
                if (!empty($me2['ok'])) {
                    // account exists and password works
                    return [$addressCandidate, $passwordCandidate, false];
                } else {
                    // cannot verify; account exists but we can't login -> cannot use this address
                    // generate a new address on next loop
                    $address = null; // force generation next iteration
                    $password = null;
                    $generatedOverall = true;
                    usleep(150000);
                    continue;
                }
            } else {
                // cannot get token: account exists but password doesn't match - generate new
                $address = null;
                $password = null;
                $generatedOverall = true;
                usleep(150000);
                continue;
            }
        }

        // Other responses: log body and retry
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
        // prepare DB insert (same schema you provided)
        $sql = "INSERT INTO `accounts` (by_user, range_id, email, email_psw, email_otp, country, num_code, phone, phone_otp, account_psw, ac_status, ac_last_used, created_at)
                VALUES ('$user_id', '$range_id', :email, :email_psw, '', :country, '', :phone, '', '@Okx#1234', NULL, NULL, NOW())";
        $stmt = $pdo->prepare($sql);

        // ensure CSV header exists
        if (!file_exists($CSV_LOG)) {
            @file_put_contents($CSV_LOG, "email,password,country,phone,created_at\n", LOCK_EX);
        }

        foreach ($phones as $phone) {
            try {
                list($email, $email_psw, $generated) = create_or_get_mailbox_verified();
            } catch (Exception $e) {
                $failed[] = ['phone' => $phone, 'reason' => 'mail creation/verify failed: ' . $e->getMessage()];
                continue;
            }

            try {
                $stmt->execute([
                    ':email' => $email,
                    ':email_psw' => $email_psw,
                    ':country' => $country,
                    ':phone' => $phone
                ]);
                $success++;

                // append to CSV log
                $line = sprintf("%s,%s,%s,%s,%s\n",
                    str_replace(',', '\,', $email),
                    str_replace(',', '\,', $email_psw),
                    str_replace(',', '\,', $country),
                    str_replace(',', '\,', $phone),
                    date('Y-m-d H:i:s')
                );
                @file_put_contents($CSV_LOG, $line, FILE_APPEND | LOCK_EX);

                $created[] = ['phone' => $phone, 'email' => $email];

            } catch (Exception $e) {
                $failed[] = ['phone' => $phone, 'reason' => 'DB insert failed: ' . $e->getMessage(), 'email' => $email];
            }

            // short sleep between loops to avoid hammering API
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
          <?php if (file_exists($CSV_LOG)): ?>
            <a href="<?php echo htmlspecialchars('/tmp/created_mailtm.csv'); ?>" class="btn btn-sm btn-outline-primary ms-2" target="_blank">Open CSV log</a>
          <?php endif; ?>
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

    <?php if (!empty($failed)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Failed</h5>
          <ul><?php foreach ($failed as $f) {
              echo '<li>'.htmlspecialchars($f['phone'] ?? 'N/A').' — '.htmlspecialchars($f['reason'] ?? 'unknown');
              if (!empty($f['email'])) echo ' (email: '.htmlspecialchars($f['email']).')';
              echo '</li>';
          } ?></ul>
          <div class="form-text">If an address already exists and the password doesn't match, the script will generate a new address automatically. Check the CSV log for created credentials.</div>
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
      <div class="form-text">Enter one phone number per line. Keep number formatting consistent.</div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary" type="submit">Add Bulk Accounts (verify)</button>
      <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
  </form>
</div>
</body>
</html>
