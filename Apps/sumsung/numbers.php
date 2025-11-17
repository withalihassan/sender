<?php
// numbers.php
// Paginated table with simple CRUD and a top form to update num_limit for the whole range.
// URL example: numbers.php?rid=8&uid=2
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

    // Update single row
    if ($action === 'update_row') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id || !$range_id) json_response(['success'=>false,'message'=>'Missing id or range.']);

        $full_text    = array_key_exists('full_text', $_POST)    ? trim((string)$_POST['full_text'])    : null;
        $data_value   = array_key_exists('data_value', $_POST)   ? trim((string)$_POST['data_value'])   : null;
        $country_name = array_key_exists('country_name', $_POST) ? trim((string)$_POST['country_name']) : null;
        $num_limit    = array_key_exists('num_limit', $_POST) && $_POST['num_limit'] !== '' ? (int)$_POST['num_limit'] : null;
        $number       = array_key_exists('number', $_POST) ? trim((string)$_POST['number']) : null;

        $check = $pdo->prepare("SELECT 1 FROM numbers WHERE id = :id AND range_id = :rid LIMIT 1");
        $check->execute([':id'=>$id, ':rid'=>$range_id]);
        if (!$check->fetch()) json_response(['success'=>false,'message'=>'Not found or wrong range.']);

        $fields = [];
        $binds = [':id'=>$id, ':rid'=>$range_id];
        if ($full_text !== null)    { $fields[] = "full_text = :full_text";   $binds[':full_text'] = $full_text === '' ? null : $full_text; }
        if ($data_value !== null)   { $fields[] = "data_value = :data_value"; $binds[':data_value'] = $data_value === '' ? null : $data_value; }
        if ($country_name !== null) { $fields[] = "country_name = :country_name"; $binds[':country_name'] = $country_name === '' ? null : $country_name; }
        if ($num_limit !== null)    { $fields[] = "num_limit = :num_limit";   $binds[':num_limit'] = $num_limit; }
        if ($number !== null)       { $fields[] = "`number` = :number";       $binds[':number'] = $number; }

        if (empty($fields)) json_response(['success'=>false,'message'=>'No fields provided.']);

        $sql = "UPDATE numbers SET " . implode(', ', $fields) . " WHERE id = :id AND range_id = :rid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($binds);
        json_response(['success'=>true,'message'=>'Row updated.']);
    }

    // Bulk update all rows in this range (full_text, data_value, country_name)
    if ($action === 'bulk_update') {
        if (!$range_id) json_response(['success'=>false,'message'=>'Missing range.']);
        $full_text_all    = array_key_exists('full_text_all', $_POST)    ? trim((string)$_POST['full_text_all'])    : null;
        $data_value_all   = array_key_exists('data_value_all', $_POST)   ? trim((string)$_POST['data_value_all'])   : null;
        $country_name_all = array_key_exists('country_name_all', $_POST) ? trim((string)$_POST['country_name_all']) : null;

        if ($full_text_all === null && $data_value_all === null && $country_name_all === null) {
            json_response(['success'=>false,'message'=>'No fields provided for bulk update.']);
        }

        $fields = [];
        $binds = [':rid' => $range_id];
        if ($full_text_all !== null)    { $fields[] = "full_text = :full_text_all";    $binds[':full_text_all'] = $full_text_all === '' ? null : $full_text_all; }
        if ($data_value_all !== null)   { $fields[] = "data_value = :data_value_all";  $binds[':data_value_all'] = $data_value_all === '' ? null : $data_value_all; }
        if ($country_name_all !== null) { $fields[] = "country_name = :country_name_all"; $binds[':country_name_all'] = $country_name_all === '' ? null : $country_name_all; }

        $sql = "UPDATE numbers SET " . implode(', ', $fields) . " WHERE range_id = :rid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($binds);
        json_response(['success'=>true,'message'=>'Bulk update complete.', 'rows' => $stmt->rowCount()]);
    }

    // NEW: Bulk update num_limit for the whole range
    if ($action === 'bulk_update_num_limit') {
        if (!$range_id) json_response(['success'=>false,'message'=>'Missing range.']);
        // Accept empty string => set NULL, otherwise integer
        $nl_raw = array_key_exists('num_limit_all', $_POST) ? trim((string)$_POST['num_limit_all']) : null;
        if ($nl_raw === null) json_response(['success'=>false,'message'=>'No value provided.']);
        $stmt = $pdo->prepare("UPDATE numbers SET num_limit = :nl WHERE range_id = :rid");
        if ($nl_raw === '') {
            $stmt->bindValue(':nl', null, PDO::PARAM_NULL);
            $stmt->bindValue(':rid', $range_id, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $nl = (int)$nl_raw;
            $stmt->bindValue(':nl', $nl, PDO::PARAM_INT);
            $stmt->bindValue(':rid', $range_id, PDO::PARAM_INT);
            $stmt->execute();
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

// GET: fetch rows for display
$stmt = $pdo->prepare("SELECT id, full_text, data_value, `number`, num_limit, belong_to, country_name FROM numbers WHERE range_id = :rid ORDER BY id DESC");
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
      <a class="btn btn-sm btn-primary" href="add_numbers.php?rid=<?= urlencode($range_id) ?>&uid=<?= urlencode($user_id) ?>">Add Bulk</a>
      <a class="btn btn-sm btn-outline-secondary" href="add_account.php">Make Account Ready</a>
    </div>
  </div>

  <!-- Top controls -->
  <div class="card mb-3 p-3">
    <div class="row g-2 align-items-end">
      <!-- Bulk update (full_text / data_value / country_name) -->
      <div class="col-auto">
        <label class="form-label mb-0">full_text (for all)</label>
        <input id="full_text_all" class="form-control" placeholder="e.g. United Kingdom (+44)">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0">data_value (for all)</label>
        <input id="data_value_all" class="form-control" placeholder="data_value">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0">country_name (for all)</label>
        <input id="country_name_all" class="form-control" placeholder="Country name">
      </div>
      <div class="col-auto">
        <button id="bulkUpdateBtn" class="btn btn-success">Update all</button>
      </div>

      <!-- NEW: num_limit update (single input updates entire range) -->
      <div class="col-auto ms-3">
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
              <th>data_value</th>
              <th>number</th>
              <th>num_limit</th>
              <th>belong_to</th>
              <th style="width:160px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-muted">No records for this range.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr data-id="<?= (int)$r['id'] ?>"
                  data-full_text="<?= e($r['full_text']) ?>"
                  data-data_value="<?= e($r['data_value']) ?>"
                  data-country_name="<?= e($r['country_name']) ?>"
                  data-number="<?= e($r['number']) ?>"
                  data-num_limit="<?= e($r['num_limit']) ?>">
                <td><?= (int)$r['id'] ?></td>
                <td><?= e($r['full_text']) ?></td>
                <td><?= e($r['data_value']) ?></td>
                <td><?= e($r['number']) ?></td>
                <td class="cell-num_limit"><?= e($r['num_limit']) ?></td>
                <td><?= $r['belong_to'] === null ? '<span class="badge bg-secondary">NULL</span>' : e($r['belong_to']) ?></td>
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

<!-- Edit modal -->
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
          <label class="form-label">data_value</label>
          <input name="data_value" id="edit_data_value" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">country_name</label>
          <input name="country_name" id="edit_country_name" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">number</label>
          <input name="number" id="edit_number" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">num_limit</label>
          <input name="num_limit" type="number" id="edit_num_limit" class="form-control">
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
    columnDefs: [{ orderable: false, targets: 6 }]
  });

  // Open edit modal
  $(document).on('click', '.btn-edit', function(){
    const $tr = $(this).closest('tr');
    const id = $tr.data('id');
    $('#modalId').text(id);
    $('#edit_id').val(id);
    $('#edit_full_text').val($tr.data('full_text') || '');
    $('#edit_data_value').val($tr.data('data_value') || '');
    $('#edit_country_name').val($tr.data('country_name') || '');
    $('#edit_number').val($tr.data('number') || '');
    $('#edit_num_limit').val($tr.data('num_limit') || '');
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
      data_value: $('#edit_data_value').val(),
      country_name: $('#edit_country_name').val(),
      number: $('#edit_number').val(),
      num_limit: $('#edit_num_limit').val()
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

  // Bulk update (full_text/data_value/country_name) -> reload
  $('#bulkUpdateBtn').on('click', function(e){
    e.preventDefault();
    if (!confirm('Apply these values to ALL rows in this range?')) return;
    const payload = {
      action: 'bulk_update',
      full_text_all: $('#full_text_all').val(),
      data_value_all: $('#data_value_all').val(),
      country_name_all: $('#country_name_all').val()
    };
    const $btn = $(this);
    $btn.prop('disabled', true).text('Updating...');
    $.post('', payload, function(resp){
      $btn.prop('disabled', false).text('Update all');
      if (resp.success) location.reload();
      else alert(resp.message || 'Bulk update failed');
    }, 'json').fail(function(){ $btn.prop('disabled', false).text('Update all'); alert('Server error'); });
  });

  // NEW: Bulk update num_limit for the whole range (no reload — update table cells in-place)
  $('#bulkNumLimitBtn').on('click', function(e){
    e.preventDefault();
    const val = $('#num_limit_all').val(); // '' or numeric
    if (!confirm('Set num_limit=' + (val === '' ? 'NULL' : val) + ' for all rows?')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Applying...');
    $.post('', { action: 'bulk_update_num_limit', num_limit_all: val }, function(resp){
      $btn.prop('disabled', false).text('Apply');
      if (resp.success) {
        // update visible num_limit column (index 4)
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
