<?php
// numbers.php
// Simplified view: show only id, full_text, number, num_limit, login_cmnt
require_once __DIR__ . '/db.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_response($data){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; }

$range_id = isset($_GET['rid']) ? (int)$_GET['rid'] : 0;
$user_id  = isset($_GET['uid'])  ? (int)$_GET['uid']  : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Delete single row
    if ($action === 'delete_number') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id || !$range_id) json_response(['success'=>false,'message'=>'Missing id or range.']);
        $check = $pdo->prepare("SELECT 1 FROM numbers WHERE id = :id AND range_id = :rid LIMIT 1");
        $check->execute([':id'=>$id, ':rid'=>$range_id]);
        if (!$check->fetch()) json_response(['success'=>false,'message'=>'Not found or wrong range.']);
        $del = $pdo->prepare("DELETE FROM numbers WHERE id = :id AND range_id = :rid");
        $del->execute([':id'=>$id, ':rid'=>$range_id]);
        json_response(['success'=>true,'message'=>'Deleted.']);
    }

    // Update single row (only allowed fields for this simplified UI)
    if ($action === 'update_row') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id || !$range_id) json_response(['success'=>false,'message'=>'Missing id or range.']);

        $full_text   = array_key_exists('full_text', $_POST) ? trim((string)$_POST['full_text']) : null;
        $number      = array_key_exists('number', $_POST) ? trim((string)$_POST['number']) : null;
        $num_limit   = array_key_exists('num_limit', $_POST) && $_POST['num_limit'] !== '' ? (int)$_POST['num_limit'] : (array_key_exists('num_limit', $_POST) ? null : null);
        $login_cmnt  = array_key_exists('login_cmnt', $_POST) ? trim((string)$_POST['login_cmnt']) : null;

        $check = $pdo->prepare("SELECT 1 FROM numbers WHERE id = :id AND range_id = :rid LIMIT 1");
        $check->execute([':id'=>$id, ':rid'=>$range_id]);
        if (!$check->fetch()) json_response(['success'=>false,'message'=>'Not found or wrong range.']);

        $fields = [];
        $binds = [':id'=>$id, ':rid'=>$range_id];
        if ($full_text !== null)  { $fields[] = "full_text = :full_text"; $binds[':full_text'] = $full_text === '' ? null : $full_text; }
        if ($number !== null)     { $fields[] = "`number` = :number";     $binds[':number'] = $number === '' ? null : $number; }
        // num_limit: allow explicit NULL when empty string submitted
        if (array_key_exists('num_limit', $_POST)) {
            if ($_POST['num_limit'] === '') {
                $fields[] = "num_limit = NULL";
            } else {
                $fields[] = "num_limit = :num_limit";
                $binds[':num_limit'] = (int)$_POST['num_limit'];
            }
        }
        if ($login_cmnt !== null) { $fields[] = "login_cmnt = :login_cmnt"; $binds[':login_cmnt'] = $login_cmnt === '' ? null : $login_cmnt; }

        if (empty($fields)) json_response(['success'=>false,'message'=>'No fields provided.']);

        $sql = "UPDATE numbers SET " . implode(', ', $fields) . " WHERE id = :id AND range_id = :rid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($binds);
        json_response(['success'=>true,'message'=>'Row updated.']);
    }

    // Bulk update num_limit for the whole range
    if ($action === 'bulk_update_num_limit') {
        if (!$range_id) json_response(['success'=>false,'message'=>'Missing range.']);
        // Accept empty string => set NULL, otherwise integer
        $nl_raw = array_key_exists('num_limit_all', $_POST) ? trim((string)$_POST['num_limit_all']) : null;
        if ($nl_raw === null) json_response(['success'=>false,'message'=>'No value provided.']);
        if ($nl_raw === '') {
            $stmt = $pdo->prepare("UPDATE numbers SET num_limit = NULL ,  login_cmnt=NULL WHERE range_id = :rid");
            $stmt->execute([':rid' => $range_id]);
        } else {
            $nl = (int)$nl_raw;
            $stmt = $pdo->prepare("UPDATE numbers SET num_limit = :nl , login_cmnt=NULL WHERE range_id = :rid");
            $stmt->execute([':nl' => $nl, ':rid' => $range_id]);
        }
        json_response(['success'=>true,'message'=>'num_limit updated for range.', 'rows' => $stmt->rowCount(), 'value' => $nl_raw === '' ? null : (int)$nl_raw]);
    }

    // Bulk delete all rows in this range
    if ($action === 'bulk_delete') {
        if (!$range_id) json_response(['success'=>false,'message'=>'Missing range.']);
        $stmt = $pdo->prepare("DELETE FROM numbers WHERE range_id = :rid");
        $stmt->execute([':rid' => $range_id]);
        json_response(['success'=>true,'message'=>'Deleted rows.', 'deleted' => $stmt->rowCount()]);
    }

    json_response(['success'=>false,'message'=>'Unknown action.']);
}

// GET: fetch rows for display (only needed columns)
$stmt = $pdo->prepare("SELECT id, full_text, `number`, num_limit, login_cmnt FROM numbers WHERE range_id = :rid ORDER BY id DESC");
$stmt->execute([':rid' => $range_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Numbers — Range <?= e($range_id) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
  <style>
    body{background:#f6f8fa;padding:18px}
    .card{box-shadow:0 6px 18px rgba(0,0,0,.04)}
    table td, table th{vertical-align:middle}
    .muted{color:#666;font-size:.9rem}
    .small-input { max-width:160px; display:inline-block; }
  </style>
</head>
<body>
<div class="container">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h4>Numbers — Range <?= e($range_id) ?></h4>
      <div class="muted">User: <?= e($user_id ?: 'N/A') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-primary" href="add_numbers.php?rid=<?= urlencode($range_id) ?>&uid=<?= urlencode($user_id) ?>" target="_blank" rel="noopener">Add Bulk</a>
      <a class="btn btn-sm btn-outline-secondary" href="add_account.php">Make Account Ready</a>
    </div>
  </div>

  <!-- Top controls: only num_limit updater + bulk delete -->
  <div class="card mb-3 p-3">
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-0">Set num_limit for range</label>
        <div class="d-flex gap-2">
          <input id="num_limit_all" type="number" min="0" class="form-control small-input" placeholder="e.g. 3">
          <button id="bulkNumLimitBtn" class="btn btn-primary">Apply</button>
        </div>
        <div class="form-text mt-1">Leave blank and click Apply to set <code>num_limit</code> to NULL for all rows.</div>
      </div>

      <div class="col-auto ms-auto">
        <button id="bulkDeleteBtn" class="btn btn-danger">Delete all in this range</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-2">
      <div class="table-responsive">
        <table id="numbersTable" class="display table table-sm" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>full_text</th>
              <th>number</th>
              <th>num_limit</th>
              <th>login Comment</th>
              <th style="width:160px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="text-muted">No records for this range.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr data-id="<?= (int)$r['id'] ?>"
                  data-full_text="<?= e($r['full_text']) ?>"
                  data-number="<?= e($r['number']) ?>"
                  data-num_limit="<?= e($r['num_limit']) ?>"
                  data-login_cmnt="<?= e($r['login_cmnt']) ?>">
                <td><?= (int)$r['id'] ?></td>
                <td><?= e($r['full_text']) ?></td>
                <td><?= e($r['number']) ?></td>
                <td class="cell-num_limit"><?= e($r['num_limit']) ?></td>
                <td><?= e($r['login_cmnt']) ?></td>
                <td>
                  <button class="btn btn-sm btn-outline-primary btn-edit">Edit</button>
                  <button class="btn btn-sm btn-outline-danger btn-delete">Delete</button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit modal (only allowed fields) -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="editForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit row <span id="modalId"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="edit_id">
        <div class="mb-2">
          <label class="form-label">full_text</label>
          <input name="full_text" id="edit_full_text" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">number</label>
          <input name="number" id="edit_number" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">num_limit</label>
          <input name="num_limit" type="number" id="edit_num_limit" class="form-control" placeholder="leave empty to set NULL">
        </div>
        <div class="mb-2">
          <label class="form-label">login Comment</label>
          <input name="login_cmnt" id="edit_login_cmnt" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button id="saveBtn" type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- jQuery, Bootstrap, DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(function(){
  const rangeId = <?= json_encode($range_id) ?>;
  const dt = $('#numbersTable').DataTable({
    pageLength: 25,
    lengthMenu: [10,25,50,100],
    order: [[0, 'desc']],
    columnDefs: [{ orderable: false, targets: 5 }]
  });

  // Open edit modal
  $(document).on('click', '.btn-edit', function(){
    const $tr = $(this).closest('tr');
    const id = $tr.data('id');
    $('#modalId').text(id);
    $('#edit_id').val(id);
    $('#edit_full_text').val($tr.data('full_text') || '');
    $('#edit_number').val($tr.data('number') || '');
    // allow empty display for NULL
    const nl = $tr.data('num_limit');
    $('#edit_num_limit').val(nl === null || nl === undefined ? '' : nl);
    $('#edit_login_cmnt').val($tr.data('login_cmnt') || '');
    const modal = new bootstrap.Modal($('#editModal'));
    modal.show();
  });

  // Save edited row -> reload page for simplicity
  $('#editForm').on('submit', function(e){
    e.preventDefault();
    const payload = {
      action: 'update_row',
      id: $('#edit_id').val(),
      full_text: $('#edit_full_text').val(),
      number: $('#edit_number').val(),
      num_limit: $('#edit_num_limit').val(), // '' -> means set NULL
      login_cmnt: $('#edit_login_cmnt').val()
    };
    $('#saveBtn').prop('disabled', true).text('Saving...');
    $.post('', payload, function(resp){
      $('#saveBtn').prop('disabled', false).text('Save');
      if (resp.success) location.reload();
      else alert(resp.message || 'Update failed');
    }, 'json').fail(function(){ $('#saveBtn').prop('disabled', false).text('Save'); alert('Server error'); });
  });

  // Delete single row (remove via DataTable API)
  $(document).on('click', '.btn-delete', function(){
    const $btn = $(this);
    const $tr = $btn.closest('tr');
    const id = $tr.data('id');
    if (!confirm('Delete ID ' + id + ' ?')) return;
    $.post('', { action: 'delete_number', id: id }, function(resp){
      if (resp.success) {
        dt.row($tr).remove().draw();
      } else {
        alert(resp.message || 'Delete failed');
      }
    }, 'json').fail(function(){ alert('Server error'); });
  });

  // Bulk update num_limit for the whole range (no reload — update table cells in-place)
  $('#bulkNumLimitBtn').on('click', function(e){
    e.preventDefault();
    const val = $('#num_limit_all').val(); // '' or numeric
    if (!confirm('Set num_limit=' + (val === '' ? 'NULL' : val) + ' for all rows?')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Applying...');
    $.post('', { action: 'bulk_update_num_limit', num_limit_all: val }, function(resp){
      $btn.prop('disabled', false).text('Apply');
      if (resp.success) {
        const displayVal = (val === '' ? '' : val);
        dt.rows().every(function(){
          const $n = $(this.node()).find('td.cell-num_limit');
          $n.text(displayVal);
        });
        alert(resp.message || 'num_limit updated for range');
      } else {
        alert(resp.message || 'Update failed');
      }
    }, 'json').fail(function(){ $btn.prop('disabled', false).text('Apply'); alert('Server error'); });
  });

  // Bulk delete all rows
  $('#bulkDeleteBtn').on('click', function(e){
    e.preventDefault();
    if (!confirm('Delete ALL numbers where range_id = <?= e($range_id) ?>? This cannot be undone.')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Deleting...');
    $.post('', { action: 'bulk_delete' }, function(resp){
      $btn.prop('disabled', false).text('Delete all in this range');
      if (resp.success) {
        dt.clear().draw();
        alert(resp.message || 'Deleted');
      } else {
        alert(resp.message || 'Bulk delete failed');
      }
    }, 'json').fail(function(){ $btn.prop('disabled', false).text('Delete all in this range'); alert('Server error'); });
  });
});
</script>
</body>
</html>
