<?php
// edit_account_improved.php
// Rewritten: improved error reporting, deeper message parsing, stronger OTP extraction
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// optional: set error log file if you want detailed server-side logs
// ini_set('error_log', __DIR__ . '/php-error.log');

require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...)
include __DIR__ . '/../session.php';

$SMTP_BASE = getenv('SMTP_DEV_BASE') ?: 'https://api.smtp.dev';
$SMTP_API_KEY = getenv('SMTP_DEV_API_KEY') ?: 'smtplabs_wu9je5CiV6ezxoELcmk2MYehAzh918uSqK75w7YvjZsRrWRH';
$API_TOKEN_BASE = "http://147.135.212.197/crapi/st/viewstats?token=RlJVSTRSQlaCbnNHeJeNgmpQZlhZdlF3e2yKR4GTYFtakWpbWJJv";

header('X-Content-Type-Options: nosniff');

function json_out($arr) {
    header('Content-Type: application/json; charset=utf-8');
    // ensure error field is never an empty string
    if (array_key_exists('error', $arr) && ($arr['error'] === null || $arr['error'] === '')) {
        $arr['error'] = 'server_error_empty';
        if (!isset($arr['debug'])) $arr['debug'] = [];
        $arr['debug']['note'] = 'original error string was empty';
    }
    echo json_encode($arr);
    exit;
}

/* ---------- HTTP helper (cURL) with improved diagnostics ---------- */
function http_request($method, $path_or_url, $body = null, $timeout = 10) {
    global $SMTP_BASE, $SMTP_API_KEY;
    $url = (preg_match('/^https?:\/\//i', $path_or_url) ? $path_or_url : rtrim($SMTP_BASE, '/') . $path_or_url);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: smtpdev-php/1.1',
        'Expect:'
    ];
    if (!empty($SMTP_API_KEY)) $headers[] = 'X-API-KEY: ' . $SMTP_API_KEY;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        $msg = 'curl_error: ' . $curlErr;
        error_log("http_request CURL ERROR: {$msg} url={$url}");
        return ['code'=>$code, 'body'=>$resp, 'error'=>$msg];
    }

    // try to parse body JSON for better message
    $decoded = @json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = null;
        if (is_array($decoded)) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? null;
        }
        if (!$msg) {
            // look for common fields
            if (is_string($resp) && trim($resp) !== '') $msg = trim($resp);
            else $msg = "http_status_{$code}";
        }
        $err = "http_error_{$code}: {$msg}";
        error_log("http_request HTTP ERROR: {$err} url={$url}");
        return ['code'=>$code, 'body'=>$resp, 'error'=>$err, 'decoded'=>$decoded];
    }

    return ['code'=>$code, 'body'=>$resp, 'error'=>null, 'decoded'=> $decoded];
}

/* ---------- helpers: HTML->text and OTP extraction (kept and slightly extended) ---------- */
function strip_html($html) {
    if (!$html) return '';
    $html = preg_replace('#(?s)<script.*?>.*?</script>#i', ' ', $html);
    $html = preg_replace('#(?s)<style.*?>.*?</style>#i', ' ', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function extract_code_from_text($text) {
    if (!$text) return null;
    // normalize whitespace and lowercase for pattern matching
    $plain = preg_replace('/\s+/', ' ', $text);
    $lower = mb_strtolower($plain);
    $patterns = [
        '/code[^0-9]{0,30}(\d{4,8})/i',
        '/otp[^0-9]{0,30}(\d{4,8})/i',
        '/verification[^0-9]{0,30}(\d{4,8})/i',
        '/pin[^0-9]{0,30}(\d{4,8})/i',
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $lower, $m)) return $m[1];
    }
    // fallback: find standalone 4-8 digit tokens
    if (preg_match('/\b(\d{6})\b/', $plain, $m)) return $m[1];
    if (preg_match('/\b(\d{4,8})\b/', $plain, $m)) return $m[1];
    return null;
}

/* ---------- message content extraction helpers ---------- */
function extract_text_from_message_obj($m) {
    // attempt multiple common field names
    $candidates = [];
    if (is_array($m)) {
        // common direct fields
        foreach (['text','body','plain','plainText','content','message','snippet','summary'] as $k) {
            if (!empty($m[$k]) && is_string($m[$k])) $candidates[] = $m[$k];
        }
        // nested html
        if (!empty($m['html']) && is_string($m['html'])) $candidates[] = strip_html($m['html']);

        // payload/parts style (common in some mail APIs)
        if (!empty($m['parts']) && is_array($m['parts'])) {
            foreach ($m['parts'] as $p) {
                if (is_array($p)) {
                    if (!empty($p['body']) && is_string($p['body'])) $candidates[] = $p['body'];
                    if (!empty($p['text']) && is_string($p['text'])) $candidates[] = $p['text'];
                    if (!empty($p['content']) && is_string($p['content'])) $candidates[] = $p['content'];
                    if (!empty($p['html']) && is_string($p['html'])) $candidates[] = strip_html($p['html']);
                }
            }
        }

        // Some APIs return 'payload' with 'parts'
        if (!empty($m['payload']) && is_array($m['payload'])) {
            $parts = $m['payload']['parts'] ?? [];
            foreach ($parts as $p) {
                if (!empty($p['body']['data'])) {
                    // maybe base64url encoded
                    $data = $p['body']['data'];
                    try { $decoded = base64_decode(str_replace(['-','_'], ['+','/'], $data)); } catch (Exception $e) { $decoded = null; }
                    if ($decoded) $candidates[] = $decoded;
                }
                if (!empty($p['mimeType']) && stripos($p['mimeType'], 'text/plain') !== false && !empty($p['body']['text'])) {
                    $candidates[] = $p['body']['text'];
                }
            }
        }

        // as last resort, try to join all string values from the message array
        if (empty($candidates)) {
            $acc = [];
            array_walk_recursive($m, function($v) use (&$acc){ if (is_string($v) && trim($v) !== '') $acc[] = $v; });
            if (!empty($acc)) $candidates[] = implode("\n", array_slice($acc, 0, 10));
        }
    }

    // return first non-empty candidate after cleaning
    foreach ($candidates as $c) {
        $s = trim(is_string($c) ? $c : json_encode($c));
        if ($s !== '') return $s;
    }
    return '';
}

/* ---------- smtp.dev-specific helpers (improved) ---------- */
function find_account_by_address($address) {
    $path = '/accounts?address=' . urlencode($address);
    $r = http_request('GET', $path, null, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'raw'=>$r['body'] ?? null];
    $j = $r['decoded'] ?? @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid_json_in_account_lookup','raw'=>$r['body'] ?? null];
    $list = $j['member'] ?? $j['items'] ?? $j;
    if (is_array($list)) {
        foreach ($list as $acct) {
            $addr = $acct['address'] ?? null;
            if ($addr && strcasecmp($addr, $address) === 0) return ['ok'=>true,'account'=>$acct];
        }
    }
    if (isset($j['address']) && strcasecmp($j['address'], $address) === 0) return ['ok'=>true,'account'=>$j];
    return ['ok'=>false,'error'=>'account_not_found','raw'=>$j];
}

function choose_mailbox_from_account($account) {
    if (!is_array($account)) return null;
    if (!empty($account['mailboxes']) && is_array($account['mailboxes'])) {
        $m = $account['mailboxes'][0];
        return $m['id'] ?? $m['path'] ?? null;
    }
    if (!empty($account['mailbox']) && is_array($account['mailbox'])) {
        $m = $account['mailbox'][0];
        return $m['id'] ?? null;
    }
    return null;
}

function list_mailbox_messages($accountId, $mailboxId, $page = 1) {
    $path = "/accounts/" . urlencode($accountId) . "/mailboxes/" . urlencode($mailboxId) . "/messages?page=" . intval($page);
    $r = http_request('GET', $path, null, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'raw'=>$r['body'] ?? null];
    $j = $r['decoded'] ?? @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid_json_messages','raw'=>$r['body'] ?? null];
    $members = $j['member'] ?? $j['items'] ?? $j['hydra:member'] ?? $j;
    if (!is_array($members)) return ['ok'=>false,'error'=>'unexpected_messages_format','raw'=>$members];
    return ['ok'=>true,'messages'=>$members,'raw'=>$j];
}

function get_message_detail($accountId, $mailboxId, $messageId) {
    $path = "/accounts/" . urlencode($accountId) . "/mailboxes/" . urlencode($mailboxId) . "/messages/" . urlencode($messageId);
    $r = http_request('GET', $path, null, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'raw'=>$r['body'] ?? null];
    $j = $r['decoded'] ?? @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid_json_message_detail','raw'=>$r['body'] ?? null];

    // normalize and extract likely text/html content
    $text = extract_text_from_message_obj($j);
    $html = '';
    if (!empty($j['html'])) $html = $j['html'];
    if (empty($text) && !empty($html)) $text = strip_html($html);

    $out = $j;
    $out['__extracted_text'] = $text;
    return ['ok'=>true,'message'=>$out];
}

/* ---------- poll_for_otp using account/mailbox endpoints (improved diagnostics) ---------- */
function poll_for_otp($address, $password_unused, $timeout = 120, $interval = 2) {
    $acctRes = find_account_by_address($address);
    if (!$acctRes['ok']) return ['ok'=>false,'error'=>$acctRes['error'] ?? 'account_lookup_failed','detail'=>$acctRes];

    $account = $acctRes['account'];
    $accountId = $account['id'] ?? null;
    if (!$accountId) return ['ok'=>false,'error'=>'account_id_missing','account'=>$account];

    $mailboxId = choose_mailbox_from_account($account);
    if (!$mailboxId) {
        $r = http_request('GET', '/accounts/' . urlencode($accountId), null, 6);
        if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'detail'=>$r];
        $j = $r['decoded'] ?? @json_decode($r['body'], true);
        if (is_array($j) && !empty($j['mailboxes']) && is_array($j['mailboxes'])) {
            $mailboxId = $j['mailboxes'][0]['id'] ?? null;
        }
    }

    if (!$mailboxId) return ['ok'=>false,'error'=>'mailbox_id_missing','account'=>$account];

    $deadline = time() + (int)$timeout;
    $seen = [];
    $collected = [];
    $page = 1;
    $last_http_errors = [];

    while (time() <= $deadline) {
        $list = list_mailbox_messages($accountId, $mailboxId, $page);
        if (!$list['ok']) {
            $last_http_errors[] = $list['error'] ?? 'list_failed';
            usleep((int)($interval * 1000000));
            continue;
        }
        $members = $list['messages'];
        // sort newest first if createdAt exists
        usort($members, function($a,$b){
            $ka = $a['createdAt'] ?? $a['created_at'] ?? '';
            $kb = $b['createdAt'] ?? $b['created_at'] ?? '';
            return strcmp($kb, $ka);
        });

        foreach ($members as $m) {
            $mid = $m['id'] ?? $m['_id'] ?? null;
            if (!$mid) {
                // try to build an id-like fingerprint to avoid duplicates
                $mid = md5(json_encode($m));
            }
            if (isset($seen[$mid])) continue;
            $seen[$mid] = true;

            // try to obtain text/html - either directly or via detail endpoint
            $body = trim($m['text'] ?? $m['body'] ?? ($m['snippet'] ?? ''));
            $html = $m['html'] ?? '';
            $subject = $m['subject'] ?? '';
            $from = $m['from'] ?? null;
            $createdAt = $m['createdAt'] ?? $m['created_at'] ?? null;

            if ($body === '' && $html === '') {
                $full = get_message_detail($accountId, $mailboxId, $mid);
                if ($full['ok']) {
                    $mf = $full['message'];
                    $body = trim($mf['__extracted_text'] ?? $mf['text'] ?? $mf['body'] ?? '');
                    $html = $mf['html'] ?? '';
                    $subject = $mf['subject'] ?? $subject;
                    $createdAt = $mf['createdAt'] ?? $mf['created_at'] ?? $createdAt;
                    $from = $mf['from'] ?? $from;
                } else {
                    // if detail fetch failed, keep scanning other messages
                    $last_http_errors[] = $full['error'] ?? 'detail_failed';
                    continue;
                }
            }

            if ($body === '') $body = strip_html($html);
            $combined = trim($subject . "\n" . $body);
            $entry = ['id'=>$mid,'subject'=>$subject,'body'=>$body,'createdAt'=>$createdAt,'from'=>$from,'combined'=>$combined];
            $collected[] = $entry;
            $code = extract_code_from_text($combined);
            if ($code) {
                return ['ok'=>true,'otp'=>$code,'messages'=>$collected];
            }
        }

        $page++;
        usleep((int)($interval * 1000000));
    }

    // timed out
    $detail = ['last_http_errors'=>$last_http_errors];
    return ['ok'=>false,'error'=>'timeout_no_otp','messages'=>$collected,'detail'=>$detail];
}

/* ---------- phone helper (unchanged apart from disciplined error messages) ---------- */
function fetch_number_messages($phone) {
    global $API_TOKEN_BASE;
    $url = $API_TOKEN_BASE . '&filternum=' . urlencode($phone);

    // Try cURL first (more robust) with a reasonable timeout
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        // follow redirects
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $curlErr) {
            error_log("fetch_number_messages: curl failed: {$curlErr} url={$url}");
            // fall through to file_get_contents fallback below
            $raw = false;
        }
    } else {
        $raw = false;
        $curlErr = 'cURL_not_available';
        $httpCode = null;
    }

    // Fallback to file_get_contents if cURL failed or not available
    if ($raw === false) {
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            error_log("fetch_number_messages: file_get_contents failed url={$url} curl_err={$curlErr}");
            return ['ok'=>false,'error'=>'failed_to_contact_number_api','url'=>$url,'curl_error'=>$curlErr,'http_code'=>$httpCode];
        }
    }

    $data = @json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("fetch_number_messages: invalid json from number API url={$url} response=" . substr($raw,0,1000));
        return ['ok'=>false,'error'=>'invalid_json_from_number_api','raw'=>substr($raw,0,1000)];
    }

    $out = [];
    if (is_array($data)) {
        foreach ($data as $row) {
            if (is_array($row)) {
                $message = null;
                if (isset($row[2]) && is_string($row[2]) && trim($row[2]) !== '') $message = $row[2];
                elseif (isset($row['message']) && is_string($row['message']) && trim($row['message']) !== '') $message = $row['message'];
                else {
                    foreach ($row as $v) {
                        if (is_string($v) && trim($v) !== '') { $message = $v; break; }
                    }
                }
                if ($message !== null) $out[] = (string)$message;
            } elseif (is_string($row) && trim($row) !== '') {
                $out[] = $row;
            }
        }
    }

    return ['ok'=>true,'messages'=>$out,'raw'=>$data];
}

/* ---------- AJAX handlers (use improved poll_for_otp and better error strings) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'fetch_email_otp') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'id_required']);
        $stmt = $pdo->prepare("SELECT email, email_psw FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) json_out(['ok'=>false,'error'=>'account_not_found']);
        if (empty($acc['email'])) json_out(['ok'=>false,'error'=>'email_missing']);

        $res = poll_for_otp($acc['email'], $acc['email_psw'] ?? null, 120, 2);
        if ($res['ok']) {
            $u = $pdo->prepare("UPDATE accounts SET email_otp = :otp WHERE id = :id");
            $u->execute([':otp'=>$res['otp'], ':id'=>$id]);
            json_out(['ok'=>true,'otp'=>$res['otp'],'messages'=>$res['messages']]);
        } else {
            // ensure error field non-empty and include diagnostics
            $debug = $res['detail'] ?? [];
            if (isset($res['messages'])) $debug['collected_messages_count'] = count($res['messages']);
            json_out(['ok'=>false,'error'=>($res['error'] ?? 'unknown_error'), 'messages'=>$res['messages'] ?? [], 'debug'=>$debug]);
        }
    } elseif ($action === 'fetch_number_otp') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'id_required']);
        $stmt = $pdo->prepare("SELECT phone FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) json_out(['ok'=>false,'error'=>'account_not_found']);
        $phone = trim($acc['phone'] ?? '');
        if ($phone === '') json_out(['ok'=>false,'error'=>'phone_empty']);
        $res = fetch_number_messages($phone);
        if (!$res['ok']) json_out(['ok'=>false,'error'=>$res['error'] ?? 'failed','raw'=>$res['raw'] ?? null]);
        $otp = null;
        foreach ($res['messages'] as $m) {
            $otp = extract_code_from_text($m);
            if ($otp) break;
        }
        if ($otp) {
            $u = $pdo->prepare("UPDATE accounts SET phone_otp = :otp WHERE id = :id");
            $u->execute([':otp'=>$otp, ':id'=>$id]);
            json_out(['ok'=>true,'otp'=>$otp,'messages'=>$res['messages']]);
        } else {
            json_out(['ok'=>false,'error'=>'no_otp_found_in_messages','messages'=>$res['messages']]);
        }
    } elseif ($action === 'save_account') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'id_required']);
        $email_psw = $_POST['email_psw'] ?? '';
        $email_otp = $_POST['email_otp'] ?? '';
        $phone_otp = $_POST['phone_otp'] ?? '';
        $account_psw = $_POST['account_psw'] ?? null;
        $ac_status = $_POST['ac_status'] ?? null;

        $sql = "UPDATE accounts SET email_psw = :email_psw, email_otp = :email_otp, phone_otp = :phone_otp";
        if ($account_psw !== null) $sql .= ", account_psw = :account_psw";
        if ($ac_status !== null) $sql .= ", ac_status = :ac_status";
        $sql .= " WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $params = [':email_psw'=>$email_psw, ':email_otp'=>$email_otp, ':phone_otp'=>$phone_otp, ':id'=>$id];
        if ($account_psw !== null) $params[':account_psw'] = $account_psw;
        if ($ac_status !== null) $params[':ac_status'] = $ac_status;

        try {
            $stmt->execute($params);
            json_out(['ok'=>true,'msg'=>'saved']);
        } catch (Exception $e) {
            error_log('DB SAVE ERROR: ' . $e->getMessage());
            json_out(['ok'=>false,'error'=>'db_error','debug'=>['exception'=>$e->getMessage()]]);
        }
    }
}

/* ---------- Render UI (unchanged markup but add more debug display) ---------- */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo "Missing ?id=..."; exit; }

$stmt = $pdo->prepare("SELECT id,email,email_psw,email_otp,country,num_code,phone,phone_otp,account_psw,ac_status,ac_last_used,created_at FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$acc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acc) { echo "Account not found"; exit; }

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Account #<?=esc($acc['id'])?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px} .mono{font-family:monospace; white-space:pre-wrap}</style>
</head>
<body>
<div class="container" style="max-width:980px">
  <h4 class="mb-3">Edit Account #<?=esc($acc['id'])?></h4>
  <form id="saveForm" class="card card-body mb-3">
    <input type="hidden" name="id" value="<?=esc($acc['id'])?>">
    <div class="row g-2 mb-2">
      <div class="col-md-3">
        <label class="form-label">Email</label>
        <input class="form-control" readonly id="email" value="<?=esc($acc['email'])?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Email Password</label>
        <input name="email_psw" id="email_psw" class="form-control" value="<?=esc($acc['email_psw'])?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Email OTP</label>
        <input name="email_otp" id="email_otp" class="form-control" value="<?=esc($acc['email_otp'])?>">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button id="btnFetchEmailOtp" type="button" class="btn btn-primary w-100">Fetch Email OTP</button>
      </div>
    </div>

    <div class="row g-2 mb-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input class="form-control" readonly id="phone" value="<?=esc($acc['phone'])?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">Phone OTP</label>
        <input name="phone_otp" id="phone_otp" class="form-control" value="<?=esc($acc['phone_otp'])?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">Account password</label>
        <input name="account_psw" id="account_psw" class="form-control" value="<?=esc($acc['account_psw'])?>">
      </div>

      <div class="col-md-2 d-flex align-items-end">
        <button id="btnFetchPhoneOtp" type="button" class="btn btn-secondary w-100">Fetch Number OTP</button>
      </div>
    </div>

    <div class="d-flex gap-2 mt-2">
      <button type="submit" class="btn btn-success">Save</button>
      <a href="index.php" class="btn btn-outline-secondary">Back</a>
      <div id="status" class="ms-3 align-self-center"></div>
    </div>
  </form>

  <div id="flow" class="card card-body mb-3" style="display:none">
    <h6>Flow / Messages / Debug</h6>
    <div id="flowContent" class="mono"></div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
function showStatus(text, ok) {
  $('#status').text(text).css('color', ok ? 'green' : 'crimson');
}
function showFlow(obj) {
  $('#flow').show();
  $('#flowContent').text(JSON.stringify(obj, null, 2));
}
function disableButtons(b) {
  $('#btnFetchEmailOtp, #btnFetchPhoneOtp, button[type=submit]').prop('disabled', b);
}

$(function(){
  $('#btnFetchEmailOtp').on('click', function(){
    disableButtons(true);
    showStatus('Starting email fetch (up to 120s)...', true);
    showFlow({phase:'start_email_polling'});
    $.post('', { action: 'fetch_email_otp', id: <?=json_encode($acc['id'])?> }, function(resp){
      disableButtons(false);
      if (!resp) { showStatus('No response from server', false); return; }
      if (resp.ok) {
        $('#email_otp').val(resp.otp);
        showStatus('Email OTP fetched: ' + resp.otp, true);
        showFlow({ok:true, otp:resp.otp, messages: resp.messages});
      } else {
        var err = resp.error || 'unknown';
        showStatus('Email OTP not found: ' + err, false);
        showFlow(resp);
      }
    }, 'json').fail(function(xhr){
      disableButtons(false);
      showStatus('Server error', false);
      var txt = xhr.responseText;
      try { txt = JSON.parse(txt); } catch(e) {}
      showFlow({error: txt});
    });
  });

  $('#btnFetchPhoneOtp').on('click', function(){
    disableButtons(true);
    showStatus('Fetching phone messages...', true);
    showFlow({phase:'start_phone_fetch'});
    $.post('', { action: 'fetch_number_otp', id: <?=json_encode($acc['id'])?> }, function(resp){
      disableButtons(false);
      if (!resp) { showStatus('No response', false); return; }
      if (resp.ok) {
        $('#phone_otp').val(resp.otp);
        showStatus('Phone OTP fetched: ' + resp.otp, true);
        showFlow({ok:true, otp:resp.otp, messages: resp.messages});
      } else {
        var err = resp.error || 'unknown';
        showStatus('Phone OTP not found: ' + err, false);
        showFlow(resp);
      }
    }, 'json').fail(function(xhr){
      disableButtons(false);
      showStatus('Server error', false);
      var txt = xhr.responseText;
      try { txt = JSON.parse(txt); } catch(e) {}
      showFlow({error: txt});
    });
  });

  $('#saveForm').on('submit', function(e){
    e.preventDefault();
    disableButtons(true);
    showStatus('Saving...', true);
    var data = $(this).serializeArray();
    data.push({name:'action', value:'save_account'});
    $.post('', data, function(resp){
      disableButtons(false);
      if (!resp) { showStatus('No response', false); return; }
      if (resp.ok) {
        showStatus('Saved', true);
        showFlow(resp);
      } else {
        showStatus('Save failed: ' + (resp.error || 'unknown'), false);
        showFlow(resp);
      }
    }, 'json').fail(function(xhr){
      disableButtons(false);
      showStatus('Server error', false);
      var txt = xhr.responseText;
      try { txt = JSON.parse(txt); } catch(e) {}
      showFlow({error: txt});
    });
  });
});
</script>
</body>
</html>
