<?php
// bulk_add_numbers.php
// Usage: ensure db.php sets $pdo (PDO instance) and session.php provides session/user if needed.
require_once __DIR__ . '/db.php';
include __DIR__ . '/../session.php'; // adjust path to your session.php as needed

$errors = [];
$created = [];
$skipped = [];
$failed = [];
$success = 0;

// Range id and user id as requested
$range_id = isset($_GET['rid']) ? (int)$_GET['rid'] : (isset($_POST['range_id']) ? (int)$_POST['range_id'] : null);
$user_id  = isset($_GET['uid']) ? (int)$_GET['uid'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

/**
 * Helper: safely echo old POST values into form inputs
 */
function old($key) {
    return htmlspecialchars($_POST[$key] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------ form processing ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $country = trim($_POST['country'] ?? '');
    $country_code_input = trim($_POST['country_code'] ?? '');
    $phones_raw = trim($_POST['phones'] ?? '');

    // normalize country code: keep digits only
    $country_code_norm = preg_replace('/\D+/', '', $country_code_input); // e.g. "44" or ""
    // DB storage format for country_code: +NNN or NULL
    $country_code_db = $country_code_norm !== '' ? ('+' . $country_code_norm) : '';

    // build full_text:
    if ($country !== '') {
        if ($country_code_db !== '') {
            $full_text = $country . ' (' . $country_code_db . ')';
        } else {
            $full_text = $country;
        }
    } else {
        $full_text = $country_code_db !== '' ? $country_code_db : '';
    }

    // normalize phone lines into array, one per non-empty line
    $lines = preg_split("/\r\n|\n|\r/", $phones_raw);
    $phones = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        // keep only digits (remove +, spaces, punctuation)
        $digits = preg_replace('/\D+/', '', $ln);
        if ($digits === '') continue;
        $phones[] = $digits; // store cleaned digits version for later processing
    }

    if (empty($phones)) {
        $errors[] = "No valid phone numbers provided.";
    } else {
        // prepare DB statements for `numbers` table
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `numbers` WHERE `number` = :number");
        $insertSql = "INSERT INTO `numbers`
            (`user_id`, `range_id`, `full_text`, `country_name`, `country_code`, `num_limit`, `number`, `belong_to`, `added_at`)
            VALUES
            (:user_id, :range_id, :full_text, :country_name, :country_code, NULL, :number, :belong_to, NOW())";
        $insertStmt = $pdo->prepare($insertSql);

        foreach ($phones as $digits) {
            // derive local number by removing leading country code if present
            $local = $digits;

            if ($country_code_norm !== '') {
                // remove leading "00" + code (e.g. 0044...)
                if (strpos($local, '00' . $country_code_norm) === 0) {
                    $local = substr($local, 2 + strlen($country_code_norm));
                }
                // remove leading code (e.g. 44...)
                elseif (strpos($local, $country_code_norm) === 0) {
                    $local = substr($local, strlen($country_code_norm));
                }
                // if the number started with a single '0' followed by the rest, do NOT strip it:
                // we only strip explicit country code prefixes.
            }

            // skip if removing country code leaves empty string
            if ($local === '' || $local === null) {
                $failed[] = ['original' => $digits, 'reason' => 'Resulting local number empty after removing country code'];
                continue;
            }

            // final normalized local number (digits only, without country code)
            // check duplicate phone (stored numbers are expected to be without country code)
            try {
                $checkStmt->execute([':number' => $local]);
                $cnt = (int)$checkStmt->fetchColumn();
            } catch (Exception $e) {
                $failed[] = ['original' => $digits, 'reason' => 'DB check failed: ' . $e->getMessage()];
                continue;
            }

            if ($cnt > 0) {
                $skipped[] = ['phone' => $local, 'reason' => 'duplicate phone'];
                continue;
            }

            // perform insert
            try {
                $binds = [
                    ':user_id' => $user_id,
                    ':range_id' => $range_id,
                    ':full_text' => $full_text !== '' ? $full_text : null,
                    ':country_name' => $country !== '' ? $country : null,
                    ':country_code' => $country_code_db !== '' ? $country_code_db : null,
                    ':number' => $local,
                    ':belong_to' => 'master'
                ];
                $insertStmt->execute($binds);
                $success++;
                $created[] = ['phone' => $local, 'full_text' => $full_text];
            } catch (Exception $e) {
                $failed[] = ['original' => $digits, 'reason' => 'DB insert failed: ' . $e->getMessage()];
            }

            // small delay to avoid hammering DB (optional)
            usleep(150000);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Bulk Add Numbers</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px}</style>
</head>
<body>
<div class="container" style="max-width:900px">
  <h3 class="mb-3">Bulk Add Numbers</h3>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <strong>Errors:</strong>
      <ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="card mb-3">
      <div class="card-body">
        <p><strong>Inserted:</strong> <?php echo (int)$success; ?> rows.</p>
        <p>
          <a href="index.php" class="btn btn-sm btn-secondary">Back to list</a>
        </p>
      </div>
    </div>

    <?php if (!empty($created)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Created</h5>
          <ul><?php foreach ($created as $c) {
              echo '<li>'.htmlspecialchars($c['phone']).' — '.htmlspecialchars($c['full_text']).'</li>';
          } ?></ul>
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
              echo '<li>'.htmlspecialchars($f['original'] ?? 'N/A').' — '.htmlspecialchars($f['reason'] ?? 'unknown').'</li>';
          } ?></ul>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" class="card card-body">
    <div class="mb-3">
      <label class="form-label">Country</label>
      <input name="country" class="form-control" value="<?php echo old('country'); ?>" placeholder="e.g. United Kingdom" />
    </div>

    <div class="mb-3">
      <label class="form-label">Country Code (e.g. 44 or +44)</label>
      <input name="country_code" class="form-control" value="<?php echo old('country_code'); ?>" placeholder="e.g. +44 or 44" />
      <div class="form-text">Numeric country code will be normalized and stored with leading + (e.g. +44). The provided country code will be removed from the start of each input number before saving.</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Phone numbers (one per line)</label>
      <textarea name="phones" rows="10" class="form-control" placeholder="+923001234567<?php echo "\n+923009876543"; ?>"><?php echo old('phones'); ?></textarea>
      <div class="form-text">One phone number per line. The script will strip the provided country code from the start of each number and store the resulting local number (digits only). Duplicate numbers (existing in DB) will be skipped.</div>
    </div>

    <!-- optional hidden fields for range -->
    <input type="hidden" name="range_id" value="<?php echo htmlspecialchars($range_id); ?>" />

    <div class="d-flex gap-2">
      <button class="btn btn-primary" type="submit">Add Bulk Numbers</button>
      <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
  </form>
</div>
</body>
</html>
