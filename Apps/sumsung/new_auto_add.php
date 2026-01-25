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
$range_id = null; // per request: range_id = null
$user_id = isset($_GET['uid']) ? (int)$_GET['uid'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

// default account password
$DEFAULT_ACCOUNT_PSW = '@Smsng#860';

// Safety cap for number of emails to create in one run. Adjust as needed.
define('MAX_CREATE', 500);

// ---------------- API helpers ----------------

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
 * Return array of domain strings (or empty array on failure)
 */
function list_domains_api() {
    $r = api_request('GET', '/domains?isActive=true&page=1');
    if (!empty($r['error'])) return [];
    $j = json_decode($r['body'], true);
    if (!is_array($j)) return [];

    $list = $j['member'] ?? $j['hydra:member'] ?? $j['items'] ?? null;
    if (!$list && isset($j[0])) $list = $j;
    if (!is_array($list)) return [];

    $out = [];
    foreach ($list as $d) {
        if (is_array($d)) {
            if (!empty($d['domain'])) $out[] = $d['domain'];
            elseif (!empty($d['name'])) $out[] = $d['name'];
            else {
                $vals = array_values($d);
                if (!empty($vals[0]) && is_string($vals[0])) $out[] = $vals[0];
            }
        } elseif (is_string($d)) {
            $out[] = $d;
        }
    }

    $out = array_values(array_unique(array_filter($out, 'strlen')));
    return $out;
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
    if (is_array($list) && count($list) > 0) return $list[0];
    return null;
}

/**
 * Create or reuse mailbox (using smtp.dev)
 * accepts $forced_domain (string) to force the domain used when generating
 * returns [$address, $password, $generatedBool]
 */
function create_or_get_mailbox_smtpdev($address = null, $password = null, $forced_domain = null, $maxAttempts = 6) {
    $attempt = 0;
    $generatedOverall = false;

    while ($attempt < $maxAttempts) {
        $attempt++;

        // generate domain/local if needed
        if (!$address) {
            if ($forced_domain) {
                $dom = $forced_domain;
            } else {
                try {
                    $dom = choose_domain();
                } catch (Exception $e) {
                    throw new Exception("choose_domain failed: " . $e->getMessage());
                }
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

        // Success (201/200/204) - account created
        if (in_array($code, [200, 201, 204])) {
            return [$addressCandidate, $passwordCandidate, ($generatedThis || $generatedOverall)];
        }

        // Conflict / validation error - maybe account exists
        if (in_array($code, [409, 422])) {
            $existing = get_account_by_address($addressCandidate);
            if ($existing && !empty($existing['address'])) {
                return [$existing['address'], $passwordCandidate, false];
            } else {
                $address = null;
                $password = null;
                $generatedOverall = true;
                usleep(150000);
                continue;
            }
        }

        // Rate limited or server busy
        if ($code === 429) {
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

        // Other unexpected response - try again until attempts exhausted
        if ($attempt >= $maxAttempts) {
            $b = is_string($body) ? $body : json_encode($body);
            throw new Exception("Unexpected create_account response (code {$code}): {$b}");
        }

        usleep(150000);
    }

    throw new Exception("Failed to create/verify mailbox after {$maxAttempts} attempts");
}

/**
 * Choose a single domain (keeps previous behavior as fallback)
 */
function choose_domain() {
    $r = api_request('GET', '/domains?isActive=true&page=1');
    if (!empty($r['error'])) throw new Exception('smtp.dev request error: ' . $r['error']);
    $j = json_decode($r['body'], true);
    if (!is_array($j)) throw new Exception('smtp.dev invalid response (not json)');

    $list = $j['member'] ?? $j['hydra:member'] ?? $j['items'] ?? null;
    if (!$list && isset($j[0])) $list = $j;

    if (!is_array($list) || count($list) === 0) {
        throw new Exception('no smtp.dev domains found');
    }

    foreach ($list as $d) {
        if (is_array($d) && !empty($d['domain'])) return $d['domain'];
        if (is_array($d) && !empty($d['name'])) return $d['name'];
    }

    $first = $list[0];
    if (is_array($first)) {
        if (!empty($first['domain'])) return $first['domain'];
        $vals = array_values($first);
        return (string)($vals[0] ?? reset($first));
    }
    return (string)$first;
}

// ------------------ prepare domain list for modal ------------------
$available_domains = list_domains_api();

// ------------------ form processing ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $count_raw = trim($_POST['count'] ?? '');
    $selected_domain = trim($_POST['domain'] ?? '');

    // validate count numeric
    if ($count_raw === '' || !ctype_digit($count_raw)) {
        $errors[] = "Please enter a valid number of emails to create.";
    } else {
        $count = (int)$count_raw;
        if ($count < 1) $errors[] = "You must create at least 1 email.";
        if ($count > MAX_CREATE) $errors[] = "Maximum allowed per run is " . MAX_CREATE . " emails.";
    }

    if ($selected_domain === '') {
        $errors[] = "Please select a domain from the popup.";
    }

    if (empty($errors)) {
        // prepare DB statements
        $checkEmailStmt = $pdo->prepare("SELECT COUNT(*) FROM `accounts` WHERE email = :email");

        $insertSql = "INSERT INTO `accounts` 
            (by_user, range_id, email, email_psw, email_otp, spot_id, profile_id, account_psw, ac_status, ac_last_used, created_at)
            VALUES (:by_user, NULL, :email, :email_psw, NULL, NULL, NULL, :account_psw, NULL, NULL, NOW())";
        $insertStmt = $pdo->prepare($insertSql);

        for ($i = 0; $i < $count; $i++) {
            // create mailbox using selected domain
            try {
                list($email, $email_psw, $generated) = create_or_get_mailbox_smtpdev(null, null, $selected_domain);
            } catch (Exception $e) {
                $failed[] = ['index' => $i + 1, 'reason' => 'mail creation/verify failed: ' . $e->getMessage()];
                continue;
            }

            // check duplicate by email
            try {
                $checkEmailStmt->execute([':email' => $email]);
                $cnt = (int)$checkEmailStmt->fetchColumn();
            } catch (Exception $e) {
                $failed[] = ['index' => $i + 1, 'reason' => 'DB check failed: ' . $e->getMessage(), 'email' => $email];
                continue;
            }

            if ($cnt > 0) {
                $skipped[] = ['index' => $i + 1, 'reason' => 'duplicate email', 'email' => $email];
                continue;
            }

            try {
                // bind and insert
                $binds = [
                    ':by_user' => $user_id,
                    ':email' => $email,
                    ':email_psw' => $email_psw,
                    ':account_psw' => $DEFAULT_ACCOUNT_PSW
                ];
                $insertStmt->execute($binds);
                $success++;
                $created[] = ['index' => $i + 1, 'email' => $email];
            } catch (Exception $e) {
                $failed[] = ['index' => $i + 1, 'reason' => 'DB insert failed: ' . $e->getMessage(), 'email' => $email];
                continue;
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
  <title>Bulk Add Accounts (smtp.dev) — create multiple</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px}</style>
</head>
<body>
<div class="container" style="max-width:990px">
  <h3 class="mb-3">Bulk Add Accounts — smtp.dev (create multiple)</h3>

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
          <ul><?php foreach ($created as $c) echo '<li>#'.htmlspecialchars($c['index']).' → '.htmlspecialchars($c['email']).'</li>'; ?></ul>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($skipped)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Skipped (duplicates)</h5>
          <ul><?php foreach ($skipped as $s) echo '<li>#'.htmlspecialchars($s['index']).' — '.htmlspecialchars($s['reason']).' '.(!empty($s['email'])? ' ('.htmlspecialchars($s['email']).')':'').'</li>'; ?></ul>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($failed)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Failed</h5>
          <ul><?php foreach ($failed as $f) {
              echo '<li>#'.htmlspecialchars($f['index'] ?? 'N/A').' — '.htmlspecialchars($f['reason'] ?? 'unknown');
              if (!empty($f['email'])) echo ' (email: '.htmlspecialchars($f['email']).')';
              echo '</li>';
          } ?></ul>
          <div class="form-text">If an address already exists on smtp.dev and password doesn't match, the script will try to reuse existing account automatically.</div>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <form method="post" class="card card-body" id="bulkForm">
    <div class="mb-3">
      <label class="form-label">How many emails to create?</label>
      <input name="count" id="countInput" class="form-control" type="number" min="1" max="<?php echo MAX_CREATE; ?>" value="<?php echo htmlspecialchars($_POST['count'] ?? '1'); ?>" required />
      <div class="form-text">Enter a number (1 - <?php echo MAX_CREATE; ?>).</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Selected domain</label>
      <div class="d-flex gap-2 align-items-center">
        <input id="selectedDomainText" type="text" class="form-control" readonly value="<?php echo htmlspecialchars($_POST['domain'] ?? ''); ?>" placeholder="No domain selected" />
        <input type="hidden" name="domain" id="domainInput" value="<?php echo htmlspecialchars($_POST['domain'] ?? ''); ?>" />
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#domainModal">Choose domain</button>
      </div>
      <div class="form-text">Click "Choose domain" to select which domain smtp.dev should use for all new mailboxes.</div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary" id="submitBtn" type="submit">Create emails</button>
      <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
  </form>
</div>

<!-- Domain selection modal -->
<div class="modal fade" id="domainModal" tabindex="-1" aria-labelledby="domainModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="domainChooseForm" onsubmit="return false;">
        <div class="modal-header">
          <h5 class="modal-title" id="domainModalLabel">Choose domain for new mailboxes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($available_domains)): ?>
            <div class="alert alert-warning">No domains were returned from smtp.dev. Please try again or ensure your API key / smtp.dev has available domains.</div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Available domains</label>
            <select id="domainSelect" class="form-select">
              <option value="">-- select domain --</option>
              <?php foreach ($available_domains as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Pick the domain you want all new addresses to use.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button id="chooseDomainBtn" type="button" class="btn btn-primary">Use selected domain</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('chooseDomainBtn').addEventListener('click', function () {
    var sel = document.getElementById('domainSelect').value || '';
    if (!sel) {
        alert('Please pick a domain from the list.');
        return;
    }
    document.getElementById('domainInput').value = sel;
    document.getElementById('selectedDomainText').value = sel;
    var modalEl = document.getElementById('domainModal');
    var modal = bootstrap.Modal.getInstance(modalEl);
    modal.hide();
});

// ensure domain selected before submit
document.getElementById('bulkForm').addEventListener('submit', function (e) {
    var domainVal = document.getElementById('domainInput').value.trim();
    if (domainVal === '') {
        // show modal to prompt selection
        var modal = new bootstrap.Modal(document.getElementById('domainModal'));
        modal.show();
        e.preventDefault();
        return false;
    }
});
</script>
</body>
</html>
