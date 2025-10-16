<?php
// edit_account.php - mail.tm port from your working Python script (fast, reliable)
require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...)
include "../session.php";

$MAILTM_BASE = getenv('MAILTM_BASE') ?: 'https://api.mail.tm';
$API_TOKEN_BASE = "http://147.135.212.197/crapi/st/viewstats?token=RlJVSTRSQlaCbnNHeJeNgmpQZlhZdlF3e2yKR4GTYFtakWpbWJJv";

header('X-Content-Type-Options: nosniff');

function json_out($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

/* ---------- HTTP helper (cURL) ---------- */
function http_request($method, $path_or_url, $body = null, $token = null, $timeout = 10) {
    global $MAILTM_BASE;
    // allow passing full URL (starts with http) or path (starts with /)
    $url = (preg_match('/^https?:\\/\\//i', $path_or_url) ? $path_or_url : rtrim($MAILTM_BASE, '/') . $path_or_url);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Accept: application/ld+json', 'Content-Type: application/json', 'User-Agent: mailtm-php/1.0'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code'=>$code, 'body'=>$resp, 'error'=>$err];
}

/* ---------- mail.tm functions (mirrors your Python) ---------- */
function get_domains() {
    $r = http_request('GET', '/domains', null, null, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error']];
    $j = @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid json','raw'=>$r['body']];
    $list = $j['hydra:member'] ?? $j['items'] ?? $j;
    if (!is_array($list) || count($list) === 0) return ['ok'=>false,'error'=>'no domains','raw'=>$j];
    return ['ok'=>true,'domains'=>$list];
}

function choose_domain() {
    $d = get_domains();
    if (!$d['ok']) throw new Exception('no mail.tm domains: ' . ($d['error'] ?? ''));
    $first = $d['domains'][0];
    return $first['domain'] ?? $first['name'] ?? array_values($first)[0];
}

function create_account($address, $password) {
    $r = http_request('POST', '/accounts', ['address'=>$address,'password'=>$password], null, 10);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'code'=>$r['code']];
    $j = @json_decode($r['body'], true);
    if ($r['code'] === 201 || $r['code'] === 200) return ['ok'=>true,'raw'=>$j];
    // return raw for debugging
    return ['ok'=>false,'code'=>$r['code'],'raw'=>$j];
}

function get_token_raw($address, $password) {
    return http_request('POST', '/token', ['address'=>$address,'password'=>$password], null, 8);
}

function get_token($address, $password) {
    $r = get_token_raw($address, $password);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'code'=>$r['code'],'raw'=>$r['body']];
    $j = @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid json','raw'=>$r['body'],'code'=>$r['code']];
    $token = $j['token'] ?? $j['access_token'] ?? $j['accessToken'] ?? null;
    if (!$token) return ['ok'=>false,'error'=>'no token in response','raw'=>$j,'code'=>$r['code']];
    return ['ok'=>true,'token'=>$token];
}

function list_messages($token) {
    $r = http_request('GET', '/messages', null, $token, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'code'=>$r['code']];
    $j = @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid json','raw'=>$r['body']];
    $members = $j['hydra:member'] ?? $j['items'] ?? $j;
    if (!is_array($members)) return ['ok'=>false,'error'=>'unexpected messages format','raw'=>$members];
    return ['ok'=>true,'messages'=>$members];
}

function get_message($token, $mid) {
    $r = http_request('GET', '/messages/' . urlencode($mid), null, $token, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error'],'code'=>$r['code']];
    $j = @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid json','raw'=>$r['body']];
    return ['ok'=>true,'message'=>$j];
}

/* ---------- helpers: HTML->text and OTP extraction ---------- */
function strip_html($html) {
    if (!$html) return '';
    $html = preg_replace('#(?s)<script.*?>.*?</script>#', ' ', $html);
    $html = preg_replace('#(?s)<style.*?>.*?</style>#', ' ', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function extract_code_from_text($text) {
    if (!$text) return null;
    $lower = mb_strtolower($text);
    $patterns = [
        '/code[^0-9]{0,30}(\d{6})/i',
        '/otp[^0-9]{0,30}(\d{6})/i',
        '/verification[^0-9]{0,30}(\d{6})/i',
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $lower, $m)) return $m[1];
    }
    // fallback: first 6-digit
    if (preg_match('/\b(\d{6})\b/', $text, $m)) return $m[1];
    // wider fallback: 4-8 digits
    if (preg_match('/\b(\d{4,8})\b/', $text, $m)) return $m[1];
    return null;
}

/* ---------- poll_for_otp (fast, mirrors Python) ---------- */
function poll_for_otp($address, $password, $timeout = 60, $interval = 2) {
    // Try token, if 401/Invalid -> attempt create account once, then retry token
    $tokenRes = get_token($address, $password);
    if (!$tokenRes['ok']) {
        $raw = $tokenRes['raw'] ?? null;
        $code = $tokenRes['code'] ?? null;
        $tryCreate = false;
        if ($code === 401) $tryCreate = true;
        if (is_array($raw) && isset($raw['message']) && stripos($raw['message'],'invalid') !== false) $tryCreate = true;
        if ($tryCreate) {
            // attempt create (ignore failure)
            create_account($address, $password);
            $tokenRes = get_token($address, $password);
        } else {
            return ['ok'=>false,'error'=>'token failed','debug'=>$tokenRes];
        }
    }

    if (!$tokenRes['ok']) return ['ok'=>false,'error'=>'token failed after create attempt','debug'=>$tokenRes];
    $token = $tokenRes['token'];

    $deadline = time() + (int)$timeout;
    $seen = [];
    $collected = [];

    while (time() <= $deadline) {
        $list = list_messages($token);
        if (!$list['ok']) {
            // if API transient error, sleep and retry quickly
            usleep((int)($interval * 1000000));
            continue;
        }
        $members = $list['messages'];
        // sort newest-first by createdAt if available
        usort($members, function($a,$b){
            $ka = $a['createdAt'] ?? '';
            $kb = $b['createdAt'] ?? '';
            return strcmp($kb, $ka);
        });
        foreach ($members as $m) {
            $mid = $m['id'] ?? null;
            if (!$mid || isset($seen[$mid])) continue;
            $seen[$mid] = true;
            // prefer message text if present in summary
            $body = trim($m['text'] ?? '');
            $html = $m['html'] ?? '';
            $subject = $m['subject'] ?? '';
            if ($body === '' && $html === '') {
                // fetch full message only when needed
                $full = get_message($token, $mid);
                if (!$full['ok']) continue;
                $mf = $full['message'];
                $body = trim($mf['text'] ?? '');
                $html = $mf['html'] ?? '';
                $subject = $mf['subject'] ?? $subject;
                $createdAt = $mf['createdAt'] ?? ($m['createdAt'] ?? null);
                $from = $mf['from'] ?? ($m['from'] ?? null);
            } else {
                $createdAt = $m['createdAt'] ?? null;
                $from = $m['from'] ?? null;
            }
            if ($body === '') $body = strip_html($html);
            $combined = trim($subject . "\n" . $body);
            $entry = [
                'id'=>$mid,
                'subject'=>$subject,
                'body'=>$body,
                'createdAt'=>$createdAt,
                'from'=>$from,
                'combined'=>$combined
            ];
            $collected[] = $entry;
            // try extract OTP now
            $code = extract_code_from_text($combined);
            if ($code) {
                return ['ok'=>true,'otp'=>$code,'messages'=>$collected];
            }
        }
        // sleep then loop
        usleep((int)($interval * 1000000));
    }
    return ['ok'=>false,'error'=>'timeout','messages'=>$collected];
}

/* ---------- phone helper (unchanged) ---------- */
function fetch_number_messages($phone) {
    global $API_TOKEN_BASE;
    $url = $API_TOKEN_BASE . '&filternum=' . urlencode($phone);
    $ctx = stream_context_create(['http' => ['timeout' => 6]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok'=>false,'error'=>'failed to contact external API','url'=>$url];
    $data = @json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['ok'=>false,'error'=>'invalid json from API','raw'=>$raw];
    }
    $out = [];
    if (is_array($data)) {
        foreach ($data as $row) {
            if (is_array($row)) {
                $message = null;
                if (isset($row[2])) $message = $row[2];
                elseif (isset($row['message'])) $message = $row['message'];
                else {
                    foreach ($row as $v) {
                        if (is_string($v) && trim($v) !== '') { $message = $v; break; }
                    }
                }
                if ($message !== null) $out[] = (string)$message;
            } elseif (is_string($row)) {
                $out[] = $row;
            }
        }
    }
    return ['ok'=>true,'messages'=>$out,'raw'=>$data];
}

/* ---------- AJAX handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'fetch_email_otp') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'id required']);
        $stmt = $pdo->prepare("SELECT email, email_psw FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) json_out(['ok'=>false,'error'=>'account not found']);
        if (empty($acc['email']) || empty($acc['email_psw'])) json_out(['ok'=>false,'error'=>'email or password missing']);
        // poll for otp quickly: 60s timeout, 2s interval
        $res = poll_for_otp($acc['email'], $acc['email_psw'], 60, 2);
        if ($res['ok']) {
            // save to db
            $u = $pdo->prepare("UPDATE accounts SET email_otp = :otp WHERE id = :id");
            $u->execute([':otp'=>$res['otp'], ':id'=>$id]);
            json_out(['ok'=>true,'otp'=>$res['otp'],'messages'=>$res['messages']]);
        } else {
            json_out(['ok'=>false,'error'=>$res['error'] ?? 'no otp','messages'=>$res['messages'] ?? [], 'debug'=>$res['debug'] ?? null]);
        }
    } elseif ($action === 'fetch_number_otp') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'id required']);
        $stmt = $pdo->prepare("SELECT phone FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) json_out(['ok'=>false,'error'=>'account not found']);
        $phone = trim($acc['phone'] ?? '');
        if ($phone === '') json_out(['ok'=>false,'error'=>'phone empty']);
        $res = fetch_number_messages($phone);
        if (!$res['ok']) json_out(['ok'=>false,'error'=>$res['error'] ?? 'failed','raw'=>$res['raw'] ?? null]);
        // try extract otp
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
            json_out(['ok'=>false,'error'=>'no otp found in messages','messages'=>$res['messages']]);
        }
    } elseif ($action === 'save_account') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'id required']);
        $email_psw = $_POST['email_psw'] ?? '';
        $email_otp = $_POST['email_otp'] ?? '';
        $phone_otp = $_POST['phone_otp'] ?? '';
        $ac_status = $_POST['ac_status'] ?? null;
        $sql = "UPDATE accounts SET email_psw = :email_psw, email_otp = :email_otp, phone_otp = :phone_otp";
        if ($ac_status !== null) $sql .= ", ac_status = :ac_status";
        $sql .= " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $params = [':email_psw'=>$email_psw, ':email_otp'=>$email_otp, ':phone_otp'=>$phone_otp, ':id'=>$id];
        if ($ac_status !== null) $params[':ac_status'] = $ac_status;
        $stmt->execute($params);
        json_out(['ok'=>true,'msg'=>'saved']);
    }
}

/* ---------- Render UI ---------- */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo "Missing ?id=..."; exit; }

$stmt = $pdo->prepare("SELECT id,email,email_psw,email_otp,country,num_code,phone,phone_otp,account_psw,ac_status,ac_last_used,created_at FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$acc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acc) { echo "Account not found"; exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Account #<?=htmlspecialchars($acc['id'])?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#f6f8fa;padding:20px} .mono{font-family:monospace; white-space:pre-wrap}</style>
</head>
<body>
<div class="container" style="max-width:980px">
  <h4 class="mb-3">Edit Account #<?=htmlspecialchars($acc['id'])?></h4>

  <form id="saveForm" class="card card-body mb-3">
    <input type="hidden" name="id" value="<?=htmlspecialchars($acc['id'])?>">
    <div class="row g-2 mb-2">
      <div class="col-md-3">
        <label class="form-label">Email</label>
        <input class="form-control" readonly id="email" value="<?=htmlspecialchars($acc['email'])?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Email Password</label>
        <input name="email_psw" id="email_psw" class="form-control" value="<?=htmlspecialchars($acc['email_psw'])?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Email OTP</label>
        <input name="email_otp" id="email_otp" class="form-control" value="<?=htmlspecialchars($acc['email_otp'])?>">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button id="btnFetchEmailOtp" type="button" class="btn btn-primary w-100">Fetch Email OTP</button>
      </div>
    </div>

    <div class="row g-2 mb-2">
      <div class="col-md-6">
        <label class="form-label">Phone</label>
        <input class="form-control" readonly id="phone" value="<?=htmlspecialchars($acc['phone'])?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Phone OTP</label>
        <input name="phone_otp" id="phone_otp" class="form-control" value="<?=htmlspecialchars($acc['phone_otp'])?>">
      </div>
      <div class="col-md-3 d-flex align-items-end">
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
    <h6>Flow / Messages</h6>
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
    showStatus('Starting email fetch (up to 60s)...', true);
    showFlow({phase:'start_email_polling'});
    $.post('', { action: 'fetch_email_otp', id: <?=json_encode($acc['id'])?> }, function(resp){
      disableButtons(false);
      if (!resp) { showStatus('No response from server', false); return; }
      if (resp.ok) {
        $('#email_otp').val(resp.otp);
        showStatus('Email OTP fetched: ' + resp.otp, true);
        showFlow({ok:true, otp:resp.otp, messages: resp.messages});
      } else {
        showStatus('Email OTP not found: ' + (resp.error || 'unknown'), false);
        showFlow(resp);
      }
    }, 'json').fail(function(xhr){
      disableButtons(false);
      showStatus('Server error', false);
      showFlow({error: xhr.responseText});
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
        showStatus('Phone OTP not found: ' + (resp.error || 'unknown'), false);
        showFlow(resp);
      }
    }, 'json').fail(function(xhr){
      disableButtons(false);
      showStatus('Server error', false);
      showFlow({error: xhr.responseText});
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
      showFlow({error: xhr.responseText});
    });
  });
});
</script>
</body>
</html>
