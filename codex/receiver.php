<?php
// receiver-ui-modernized.php
// Minimal backend logic kept identical to original; UI modernized.
// Expects ../db.php to define $pdo (PDO)

require __DIR__ . '/../db.php';

// ---------- config ----------
$API_TOKEN_BASE = "http://147.135.212.197/crapi/st/viewstats?token=RlJVSTRSQlaCbnNHeJeNgmpQZlhZdlF3e2yKR4GTYFtakWpbWJJv";
$PER_PAGE = 25;

// tiny json responder
function json_out($arr){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// ---------- AJAX endpoints ----------
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // list numbers (from DB) for set_id
    if ($action === 'list_numbers') {
        $set_id = isset($_GET['set_id']) ? (int) $_GET['set_id'] : 0;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        if ($set_id <= 0) json_out(['success' => false, 'message' => 'set_id required']);

        $offset = ($page - 1) * $PER_PAGE;

        // total
        $c = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE set_id = :set_id");
        $c->execute([':set_id' => $set_id]);
        $total = (int) $c->fetchColumn();

        // rows
        $q = $pdo->prepare("SELECT id, phone_number FROM allowed_numbers WHERE set_id = :set_id ORDER BY id ASC LIMIT :l OFFSET :o");
        $q->bindValue(':set_id', $set_id, PDO::PARAM_INT);
        $q->bindValue(':l', $PER_PAGE, PDO::PARAM_INT);
        $q->bindValue(':o', $offset, PDO::PARAM_INT);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        json_out(['success' => true, 'total' => $total, 'per_page' => $PER_PAGE, 'page' => $page, 'rows' => $rows]);
    }

    // fetch messages from external token API using file_get_contents (simple)
    if ($action === 'fetch_messages') {
        $phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
        if ($phone === '') json_out(['success' => false, 'message' => 'phone required']);

        // Build URL exactly as you specified: token + &filternum=NUMBER
        $url = $API_TOKEN_BASE . '&filternum=' . urlencode($phone);
        
        // Using file_get_contents as requested
        // set a short timeout
        $ctx = stream_context_create(['http' => ['timeout' => 6]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            json_out(['success' => false, 'message' => 'failed to contact external API', 'url' => $url]);
        }

        $data = @json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // return raw for debugging
            json_out(['success' => false, 'message' => 'invalid json from API', 'raw' => $raw]);
        }

        $out = [];

        // The API you showed: $data[0][2] = message, $data[0][3] = timestamp
        // We'll handle arrays like that, and also generic arrays of rows.
        if (is_array($data)) {
            // If top-level is numeric-indexed and first item is numeric-indexed array, iterate rows
            foreach ($data as $row) {
                if (!is_array($row)) continue;

                $message = null;
                $rawTime = null;

                // most common structure
                if (isset($row[2])) $message = $row[2];
                if (isset($row[3])) $rawTime = $row[3];

                // fallback: first non-empty string as message
                if ($message === null) {
                    foreach ($row as $v) {
                        if (is_string($v) && strlen(trim($v)) > 0) { $message = $v; break; }
                    }
                }
                // fallback: any date-like string for time
                if ($rawTime === null) {
                    foreach ($row as $v) {
                        if (is_string($v) && preg_match('/\d{4}-\d{2}-\d{2}/', $v)) { $rawTime = $v; break; }
                    }
                }

                if ($message !== null) {
                    // convert rawTime to unix timestamp
                    $ts = time();
                    if (!empty($rawTime)) {
                        $try = strtotime($rawTime);
                        if ($try !== false) $ts = $try;
                        else {
                            // sometimes API gives epoch in ms or s; try numeric
                            if (is_numeric($rawTime)) {
                                $n = (int)$rawTime;
                                // if looks like milliseconds (13 digits), convert
                                $ts = ($n > 9999999999) ? (int)($n / 1000) : $n;
                            }
                        }
                    }
                    $out[] = [
                        'message' => (string)$message,
                        'raw_time' => $rawTime ?: date("Y-m-d H:i:s", $ts),
                        'formatted_time' => date("d-M-Y h:i A", $ts),
                        'ts' => (int)$ts
                    ];
                }
            }
        }

        // sort by ts ascending
        usort($out, function($a, $b){ return $a['ts'] <=> $b['ts']; });

        json_out(['success' => true, 'messages' => $out, 'raw' => $data]);
    }

    json_out(['success' => false, 'message' => 'unknown action']);
}

// ---------- simple UI HTML ----------
$set_id = isset($_GET['set_id']) ? (int) $_GET['set_id'] : 0;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Code-X SMS Receiver — Modern</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
:root{
  --bg:#f4f7fb; --card:#ffffff; --muted:#6b7280; --accent:#2563eb; --accent-2:#06b6d4;
  --radius:12px; --shadow: 0 10px 30px rgba(17,24,39,0.08);
}
*{box-sizing:border-box;font-family:'Inter',system-ui,Segoe UI,Roboto,Arial,sans-serif}
html,body{height:100%;margin:0;background:linear-gradient(180deg,#f8fbff 0%, #f4f7fb 100%);color:#0f172a}
.container{max-width:1200px;margin:28px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;box-shadow:var(--shadow)}
.title{font-size:18px;font-weight:600}
.subtitle{font-size:13px;color:var(--muted)}
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px}
.grid{display:grid;grid-template-columns:380px 1fr;gap:18px}
.left{min-height:320px}
.right{min-height:320px}
.numbers-list{max-height:62vh;overflow:auto}
table{width:100%;border-collapse:collapse}
thead th{font-size:12px;text-align:left;color:var(--muted);padding:10px 8px}
tbody td{padding:10px 8px;border-top:1px solid #f1f5f9}
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;border:0;cursor:pointer;font-weight:600}
.btn-activate{background:linear-gradient(90deg,#10b981,#06b6d4);color:#fff}
.btn-deactivate{background:#ef4444;color:#fff}
.btn-ghost{background:transparent;border:1px solid #e6eefb;padding:6px 10px}
.search{width:100%;padding:10px;border-radius:10px;border:1px solid #e6eefb;margin-bottom:10px}
.pager{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap}
.pager span{padding:8px 10px;border-radius:8px;background:#f1f5f9;cursor:pointer}
.pager .curr{background:linear-gradient(90deg,#eef2ff,#eef6ff);font-weight:700}
.controls{display:flex;gap:8px;align-items:center}
.poll-select{padding:8px;border-radius:8px;border:1px solid #e6eefb}
.msgbox{max-height:68vh;overflow:auto;padding:12px}
.msg{background:linear-gradient(180deg,#ffffff,#fbfdff);padding:12px;border-radius:12px;margin-bottom:12px;border:1px solid #eef3ff}
.msg .meta{display:flex;justify-content:space-between;align-items:center;gap:8px}
.msg .time{font-size:12px;color:var(--muted)}
.msg .text{margin-top:8px;white-space:pre-wrap}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;background:#f1f5f9;font-weight:600}
.empty{color:var(--muted);padding:18px;text-align:center}
.small{font-size:13px;color:var(--muted)}
.top-actions{display:flex;gap:8px;align-items:center}
.copy-btn{background:#f8fafc;border:1px solid #e6eefb;padding:6px 8px;border-radius:8px;cursor:pointer}
@media (max-width:880px){.grid{grid-template-columns:1fr;}.left{order:2}.right{order:1}}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="brand">
      <div class="logo">SMS</div>
      <div>
        <div class="title">Code-X SMS Receiver — Modern</div>
        <div class="subtitle">Dev-friendly SMS inbox for testing and automation.</div>
      </div>
    </div>
    <div class="top-actions">
      <div class="small">Current set_id: <strong id="setIdTop"><?php echo htmlspecialchars($set_id); ?></strong></div>
      <button id="clearMessages" class="btn btn-ghost">Clear messages</button>
    </div>
  </div>

  <div class="grid">
    <div class="left card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <div style="font-weight:700">Numbers</div>
          <div class="small">Open this page with <code>?set_id=123</code>. Shows <?php echo (int)$PER_PAGE; ?> per page.</div>
        </div>
        <div class="badge" id="numbersCount">—</div>
      </div>

      <input id="searchNumbers" class="search" placeholder="Search numbers...">

      <div class="numbers-list card" id="numbersWrap">
        <table>
          <thead><tr><th style="width:10%">ID</th><th style="width:55%">Number</th><th style="width:35%">Action</th></tr></thead>
          <tbody id="tbody"><tr><td colspan="3" class="empty">Loading...</td></tr></tbody>
        </table>
      </div>

      <div class="pager" id="pager"></div>
    </div>

    <div class="right card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <div style="font-weight:700">Receiving SMS</div>
          <div class="small">Activate a number to show existing messages and poll for new ones.</div>
        </div>
        <div class="controls">
          Poll every
          <select id="interval" class="poll-select"><option value="5000" selected>5s</option><option value="3000">3s</option><option value="2000">2s</option></select>
          <button id="pauseAll" class="btn btn-ghost">Pause all</button>
        </div>
      </div>

      <div id="active" style="margin-bottom:12px"></div>

      <div class="msgbox" id="messages"><div class="empty">No messages yet — activate a number to begin.</div></div>
    </div>
  </div>
</div>

<script>
(function($){
  const endpoint = 'receiver.php'; // file is self-hosted; keep same endpoint
  const perPage = <?php echo (int)$PER_PAGE; ?>;
  const setId = <?php echo (int)$set_id; ?>;
  let page = 1;
  let total = 0;

  // simple state: phone -> { timerId, lastTs }
  const active = {};

  // load numbers from DB
  function loadNumbers(p=1){
    page = p;
    $('#tbody').html('<tr><td colspan="3" class="small">Loading...</td></tr>');
    $.get(endpoint, { action: 'list_numbers', set_id: setId, page: page }, function(res){
      if (!res.success) { $('#tbody').html('<tr><td colspan="3" class="empty">Error loading numbers</td></tr>'); return; }
      total = res.total;
      const rows = res.rows || [];
      $('#numbersCount').text(total);
      if (rows.length === 0) {
        $('#tbody').html('<tr><td colspan="3" class="empty">No numbers in this set</td></tr>');
        renderPager();
        return;
      }
      let html = '';
      rows.forEach(r => {
        const pn = $('<div>').text(r.phone_number).html();
        html += `<tr>
          <td>${r.id}</td>
          <td><strong>${pn}</strong></td>
          <td>
            <button class="btn copy-btn" data-phone="${pn}" title="Copy">Copy</button>
            <button class="btn activateBtn btn-activate" data-phone="${pn}">Activate</button>
          </td>
        </tr>`;
      });
      $('#tbody').html(html);
      renderPager();
    }, 'json').fail(function(){ $('#tbody').html('<tr><td colspan="3" class="empty">Server error</td></tr>'); });
  }

  function renderPager(){
    const pages = Math.ceil(total / perPage);
    if (pages <= 1) { $('#pager').html(''); return; }
    let out = '';
    for (let i=1;i<=pages;i++){
      out += `<span class="${i===page?'curr':''}" data-page="${i}">${i}</span>`;
    }
    $('#pager').html(out);
  }

  // fetch messages from server-proxy; server returns messages with numeric ts
  function fetchMessages(phone){
    $.get(endpoint, { action: 'fetch_messages', phone: phone }, function(res){
      if (!res.success) {
        console.warn('fetch_messages error', res);
        return;
      }
      const msgs = res.messages || [];
      msgs.sort((a,b) => (a.ts||0) - (b.ts||0));
      let maxTs = active[phone] && active[phone].lastTs ? active[phone].lastTs : 0;
      msgs.forEach(m => {
        const ts = m.ts || Math.floor(Date.now()/1000);
        if (!active[phone] || ts > (active[phone].lastTs || 0)) {
          appendMessage(phone, m.formatted_time || m.raw_time || '', m.message || '');
          if (ts > maxTs) maxTs = ts;
        }
      });
      if (!active[phone]) active[phone] = {};
      active[phone].lastTs = maxTs;
    }, 'json');
  }

  function appendMessage(phone, time, text){
    const safeText = $('<div>').text(text).html();
    const safePhone = $('<div>').text(phone).html();
    const html = `<div class="msg" data-phone="${safePhone}">
      <div class="meta"><div><strong>${safePhone}</strong></div><div class="time">${time}</div></div>
      <div class="text">${safeText}</div>
    </div>`;
    const $messages = $('#messages');
    // remove placeholder empty
    $messages.find('.empty').remove();
    $messages.append(html);
    $messages.scrollTop($messages.prop('scrollHeight'));
  }

  // activate / deactivate
  $(document).on('click', '.activateBtn', function(){
    const phone = $(this).data('phone');
    const $btn = $(this);
    if (active[phone] && active[phone].timerId){
      // deactivate
      clearInterval(active[phone].timerId);
      delete active[phone];
      $btn.text('Activate').removeClass('btn-deactivate').addClass('btn-activate');
      $('#active').find(`[data-phone-badge="${phone}"]`).remove();
    } else {
      // activate
      $btn.text('Deactivate').removeClass('btn-activate').addClass('btn-deactivate');
      $('#active').append(`<span data-phone-badge="${phone}" class="badge" style="margin-right:8px">${phone}</span>`);
      active[phone] = { lastTs: 0 };
      fetchMessages(phone);
      const interval = parseInt($('#interval').val()) || 5000;
      const tid = setInterval(()=> fetchMessages(phone), interval);
      active[phone].timerId = tid;
    }
  });

  // copy button
  $(document).on('click', '.copy-btn', function(){
    const phone = $(this).data('phone');
    navigator.clipboard && navigator.clipboard.writeText(phone).then(()=>{
      $(this).text('Copied');
      const btn = this;
      setTimeout(()=> $(btn).text('Copy'), 1200);
    }).catch(()=> alert('Copy not supported'));
  });

  // pager click
  $(document).on('click', '#pager span', function(){ const p = $(this).data('page'); if (p) loadNumbers(p); });

  // change interval -> restart timers
  $('#interval').on('change', function(){
    const newInt = parseInt($(this).val());
    Object.keys(active).forEach(phone=>{
      if (active[phone] && active[phone].timerId){
        clearInterval(active[phone].timerId);
        active[phone].timerId = setInterval(()=> fetchMessages(phone), newInt);
      }
    });
  });

  // pause all
  $('#pauseAll').on('click', function(){
    Object.keys(active).forEach(phone=>{
      if (active[phone] && active[phone].timerId){
        clearInterval(active[phone].timerId);
        active[phone].timerId = null;
      }
    });
    $(this).text('Resumed').toggleClass('btn-ghost');
    setTimeout(()=> $(this).text('Pause all').toggleClass('btn-ghost'), 900);
  });

  // clear messages
  $('#clearMessages').on('click', function(){ $('#messages').empty().append('<div class="empty">No messages yet — activate a number to begin.</div>'); });

  // search numbers (client-side naive filter)
  $('#searchNumbers').on('input', function(){
    const q = $(this).val().trim().toLowerCase();
    if (!q){ $('#tbody tr').show(); return; }
    $('#tbody tr').each(function(){
      const txt = $(this).find('td:nth-child(2)').text().toLowerCase();
      $(this).toggle(txt.indexOf(q) !== -1);
    });
  });

  // init
  if (!setId) $('#tbody').html('<tr><td colspan="3" class="empty">Open with ?set_id=...</td></tr>');
  $('#setIdTop').text(setId || '—');
  loadNumbers(1);

})(jQuery);
</script>
</body>
</html>
