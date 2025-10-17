<?php
// edit_account.php - adjusted to use smtp.dev account/mailbox message endpoints
require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...)
include "../session.php";

$SMTP_BASE = getenv('SMTP_DEV_BASE') ?: 'https://api.smtp.dev';
$SMTP_API_KEY = getenv('SMTP_DEV_API_KEY') ?: 'smtplabs_wu9je5CiV6ezxoELcmk2MYehAzh918uSqK75w7YvjZsRrWRH';
$API_TOKEN_BASE = "http://147.135.212.197/crapi/st/viewstats?token=RlJVSTRSQlaCbnNHeJeNgmpQZlhZdlF3e2yKR4GTYFtakWpbWJJv";

header('X-Content-Type-Options: nosniff');

function json_out($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

/* ---------- HTTP helper (cURL) ---------- */
function http_request($method, $path_or_url, $body = null, $timeout = 10) {
    global $SMTP_BASE, $SMTP_API_KEY;
    $url = (preg_match('/^https?:\\/\\//i', $path_or_url) ? $path_or_url : rtrim($SMTP_BASE, '/') . $path_or_url);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: smtpdev-php/1.0'
    ];
    if (!empty($SMTP_API_KEY)) {
        $headers[] = 'X-API-KEY: ' . $SMTP_API_KEY;
    }
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
    if (preg_match('/\b(\d{6})\b/', $text, $m)) return $m[1];
    if (preg_match('/\b(\d{4,8})\b/', $text, $m)) return $m[1];
    return null;
}

/* ---------- smtp.dev-specific helpers ---------- */

function find_account_by_address($address) {
    // GET /accounts?address=user@example.com
    $path = '/accounts?address=' . urlencode($address);
    $r = http_request('GET', $path, null, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error']];
    $j = @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid json','raw'=>$r['body']];
    // docs use "member" collection or direct array
    $list = $j['member'] ?? $j;
    if (!is_array($list)) return ['ok'=>false,'error'=>'unexpected accounts response','raw'=>$j];
    // choose first entry that matches address
    foreach ($list as $acct) {
        $addr = $acct['address'] ?? null;
        if ($addr && strcasecmp($addr, $address) === 0) {
            return ['ok'=>true,'account'=>$acct];
        }
    }
    // fallback: if single account was returned as object (not array)
    if (isset($j['address']) && strcasecmp($j['address'], $address) === 0) {
        return ['ok'=>true,'account'=>$j];
    }
    return ['ok'=>false,'error'=>'account not found','raw'=>$j];
}

function choose_mailbox_from_account($account) {
    // account may include 'mailboxes' array with 'id' and 'path'
    if (!is_array($account)) return null;
    if (!empty($account['mailboxes']) && is_array($account['mailboxes'])) {
        $m = $account['mailboxes'][0];
        return $m['id'] ?? null;
    }
    // some responses might expose mailboxes under different names; try to call account detail endpoint if id present
    return null;
}

function list_mailbox_messages($accountId, $mailboxId, $page = 1) {
    $path = "/accounts/" . urlencode($accountId) . "/mailboxes/" . urlencode($mailboxId) . "/messages?page=" . intval($page);
    $r = http_request('GET', $path, null, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error']];
    $j = @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid json','raw'=>$r['body'],'code'=>$r['code']];
    // messages usually in member / items / hydra:member / direct array
    $members = $j['member'] ?? $j['items'] ?? $j;
    if (!is_array($members)) return ['ok'=>false,'error'=>'unexpected messages format','raw'=>$members];
    return ['ok'=>true,'messages'=>$members,'raw'=>$j];
}

function get_message_detail($accountId, $mailboxId, $messageId) {
    $path = "/accounts/" . urlencode($accountId) . "/mailboxes/" . urlencode($mailboxId) . "/messages/" . urlencode($messageId);
    $r = http_request('GET', $path, null, 8);
    if ($r['error']) return ['ok'=>false,'error'=>$r['error']];
    $j = @json_decode($r['body'], true);
    if (!is_array($j)) return ['ok'=>false,'error'=>'invalid json','raw'=>$r['body']];
    return ['ok'=>true,'message'=>$j];
}

/* ---------- poll_for_otp using account/mailbox endpoints ---------- */
function poll_for_otp($address, $password_unused, $timeout = 120, $interval = 2) {
    // smtp.dev authenticates requests by X-API-KEY, account password isn't used for API calls here,
    // but we keep signature compatible with your call sites.
    $acctRes = find_account_by_address($address);
    if (!$acctRes['ok']) return ['ok'=>false,'error'=>'account_lookup_failed','detail'=>$acctRes];

    $account = $acctRes['account'];
    $accountId = $account['id'] ?? null;
    if (!$accountId) return ['ok'=>false,'error'=>'account_id_missing','raw'=>$account];

    $mailboxId = choose_mailbox_from_account($account);
    // if mailboxes not present in the account object, attempt GET /accounts/{id} to fetch mailboxes
    if (!$mailboxId) {
        $r = http_request('GET', '/accounts/' . urlencode($accountId), null, 6);
        if ($r['error']) return ['ok'=>false,'error'=>$r['error']];
        $j = @json_decode($r['body'], true);
        if (is_array($j) && !empty($j['mailboxes']) && is_array($j['mailboxes'])) {
            $mailboxId = $j['mailboxes'][0]['id'] ?? null;
        }
    }

    if (!$mailboxId) return ['ok'=>false,'error'=>'mailbox_id_missing','account'=>$account];

    $deadline = time() + (int)$timeout;
    $seen = [];
    $collected = [];

    $page = 1;
    while (time() <= $deadline) {
        $list = list_mailbox_messages($accountId, $mailboxId, $page);
        if (!$list['ok']) {
            // transient error — wait and retry
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
            if (!$mid || isset($seen[$mid])) continue;
            $seen[$mid] = true;

            // try to get details for richer content if body/html not present
            $body = trim($m['text'] ?? $m['body'] ?? '');
            $html = $m['html'] ?? '';
            $subject = $m['subject'] ?? '';
            if ($body === '' && $html === '') {
                $full = get_message_detail($accountId, $mailboxId, $mid);
                if (!$full['ok']) continue;
                $mf = $full['message'];
                $body = trim($mf['text'] ?? $mf['body'] ?? '');
                $html = $mf['html'] ?? '';
                $subject = $mf['subject'] ?? $subject;
                $createdAt = $mf['createdAt'] ?? $mf['created_at'] ?? ($m['createdAt'] ?? $m['created_at'] ?? null);
                $from = $mf['from'] ?? ($m['from'] ?? null);
            } else {
                $createdAt = $m['createdAt'] ?? $m['created_at'] ?? null;
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
            $code = extract_code_from_text($combined);
            if ($code) {
                return ['ok'=>true,'otp'=>$code,'messages'=>$collected];
            }
        }

        // if there are multiple pages, fetch next; otherwise reset to page 1 after short wait
        $page++;
        // small backoff to avoid rate limits
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

/* ---------- AJAX handlers (same as before but use new poll_for_otp) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'fetch_email_otp') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'id required']);
        $stmt = $pdo->prepare("SELECT email, email_psw FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) json_out(['ok'=>false,'error'=>'account not found']);
        if (empty($acc['email'])) json_out(['ok'=>false,'error'=>'email missing']);
        // poll for otp quickly: 120s timeout, 2s interval
        $res = poll_for_otp($acc['email'], $acc['email_psw'] ?? null, 120, 2);
        if ($res['ok']) {
            $u = $pdo->prepare("UPDATE accounts SET email_otp = :otp WHERE id = :id");
            $u->execute([':otp'=>$res['otp'], ':id'=>$id]);
            json_out(['ok'=>true,'otp'=>$res['otp'],'messages'=>$res['messages']]);
        } else {
            json_out(['ok'=>false,'error'=>$res['error'] ?? 'no otp','messages'=>$res['messages'] ?? [], 'debug'=>$res['detail'] ?? null]);
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
            json_out(['ok'=>false,'error'=>'db error: '.$e->getMessage()]);
        }
    }
}

/* ---------- Render UI (unchanged) ---------- */
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
