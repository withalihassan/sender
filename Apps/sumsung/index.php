<?php
// ranges.php
require_once __DIR__ . '/db.php'; // must set $pdo = new PDO(...)
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}


// helper to send JSON and exit
function json_exit($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// Handle AJAX CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'add') {
            $range_name = trim((string)($_POST['range_name'] ?? ''));
            if ($range_name === '') json_exit(['ok' => false, 'msg' => 'Range name required']);
            // by_user: prefer logged-in user id, else session id
            $by_user = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : session_id();

            $stmt = $pdo->prepare("INSERT INTO ranges (by_user, range_name, added_date) VALUES (:by_user, :range_name, NOW())");
            $stmt->execute([':by_user' => $by_user, ':range_name' => $range_name]);

            $newId = (int)$pdo->lastInsertId();
            json_exit(['ok' => true, 'id' => $newId, 'msg' => 'Added']);
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $range_name = trim((string)($_POST['range_name'] ?? ''));
            if ($id <= 0) json_exit(['ok' => false, 'msg' => 'Invalid id']);
            if ($range_name === '') json_exit(['ok' => false, 'msg' => 'Range name required']);

            $stmt = $pdo->prepare("UPDATE ranges SET range_name = :range_name WHERE id = :id");
            $stmt->execute([':range_name' => $range_name, ':id' => $id]);

            json_exit(['ok' => true, 'msg' => 'Updated']);
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_exit(['ok' => false, 'msg' => 'Invalid id']);

            $stmt = $pdo->prepare("DELETE FROM ranges WHERE id = :id");
            $stmt->execute([':id' => $id]);

            json_exit(['ok' => true, 'msg' => 'Deleted']);
        }

        json_exit(['ok' => false, 'msg' => 'Unknown action']);
    } catch (Exception $e) {
        json_exit(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
    }
}
$by_user = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : session_id();
// For GET: fetch all records (we render with a while loop)
$stmt = $pdo->query("SELECT `id`, `by_user`, `range_name`, `added_date` FROM `ranges` Where by_user='$by_user' ORDER BY id DESC");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sets — List & CRUD</title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- jQuery + DataTables -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

  <style>
    body { background:#f6f8fa; padding:20px; }
    .dataTables_wrapper .dataTables_paginate .paginate_button { padding:0.2rem 0.6rem; }
  </style>
</head>
<body>
<div class="container" style="max-width:1000px">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>Sets</h4>
    <div>
      <?php if (!empty($_SESSION['username'])): ?>
        <small class="text-muted me-2">Signed in as <?php echo htmlspecialchars($_SESSION['username']); ?></small>
      <?php endif; ?>
      <a href="#" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">Add Range</a>
      <a href="logout.php" class="btn btn-sm btn-danger">Logout</a>
      <!-- <div><a  class="btn btn-danger"></a></div> -->
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body p-2">
      <table id="rangesTable" class="display table table-striped" style="width:100%">
        <thead>
          <tr>
            <th>ID</th>
            <th>By User</th>
            <th>Range Name</th>
            <th>Added Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php
        // Use while loop to display records as requested
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['id'];
            $by_user = htmlspecialchars($row['by_user']);
            $range_name = htmlspecialchars($row['range_name']);
            $added_date = htmlspecialchars($row['added_date']);
            echo "<tr data-id=\"{$id}\">";
            echo "<td>{$id}</td>";
            echo "<td>{$by_user}</td>";
            echo "<td class=\"range_name_cell\">{$range_name}</td>";
            echo "<td>{$added_date}</td>";
            echo "<td>
                    <button class=\"btn btn-sm btn-outline-secondary btn-edit\" data-id=\"{$id}\" data-range=\"" . htmlspecialchars($row['range_name'], ENT_QUOTES) . "\">Edit</button>
                    <button class=\"btn btn-sm btn-outline-danger btn-delete ms-1\" data-id=\"{$id}\">Delete</button>
                    <a href=\"numbers.php?rid={$id}&uid={$by_user}\" target=\"_blank\" ><button class=\"btn btn-sm btn-outline-success ms-1\">Open</button></a>
                  </td>";
            echo "</tr>";
        }
        ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  $by_user = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : session_id();
  ?>
  <a href="accounts.php?uid=<?php echo $by_user;?>" target="_blank"><button class="btn btn-sm btn-outline-primary ms-1">Open Emails</button></a>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="addForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Range</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="addAlert"></div>
        <div class="mb-3">
          <label class="form-label">Range Name</label>
          <input name="range_name" class="form-control" required />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" type="submit">Add</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="editForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Range</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="editAlert"></div>
        <input type="hidden" name="id" id="edit_id" />
        <div class="mb-3">
          <label class="form-label">Range Name</label>
          <input name="range_name" id="edit_range_name" class="form-control" required />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Bootstrap JS (modal) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
  // Initialize DataTable
  var table = $('#rangesTable').DataTable({
    pageLength: 10,
    lengthChange: true,
    ordering: true,
    columnDefs: [
      { orderable: false, targets: 4 } // actions not orderable
    ]
  });

  // Add
  $('#addForm').on('submit', function(e) {
    e.preventDefault();
    var form = $(this);
    var val = $.trim(form.find('input[name="range_name"]').val());
    if (val === '') return;

    $.post('', { action: 'add', range_name: val }, function(resp) {
      if (resp.ok) {
        // reload page to show new row (keeps server-side while loop simple)
        location.reload();
      } else {
        $('#addAlert').html('<div class="alert alert-danger">' + (resp.msg || 'Error') + '</div>');
      }
    }, 'json').fail(function() {
      $('#addAlert').html('<div class="alert alert-danger">Request failed</div>');
    });
  });

  // Delete
  $('#rangesTable').on('click', '.btn-delete', function() {
    if (!confirm('Delete this range?')) return;
    var id = $(this).data('id');
    $.post('', { action: 'delete', id: id }, function(resp) {
      if (resp.ok) {
        // remove row from DataTable
        var row = $('#rangesTable').find('tr[data-id="' + id + '"]');
        table.row(row).remove().draw(false);
      } else {
        alert(resp.msg || 'Delete failed');
      }
    }, 'json').fail(function() {
      alert('Request failed');
    });
  });

  // Open edit modal
  $('#rangesTable').on('click', '.btn-edit', function() {
    var id = $(this).data('id');
    var range = $(this).data('range');
    $('#edit_id').val(id);
    $('#edit_range_name').val(range);
    $('#editAlert').html('');
    var ed = new bootstrap.Modal(document.getElementById('editModal'));
    ed.show();
  });

  // Update
  $('#editForm').on('submit', function(e) {
    e.preventDefault();
    var id = $('#edit_id').val();
    var range = $.trim($('#edit_range_name').val());
    if (range === '') return;

    $.post('', { action: 'update', id: id, range_name: range }, function(resp) {
      if (resp.ok) {
        // update row cell in place
        var row = $('#rangesTable').find('tr[data-id="' + id + '"]');
        row.find('.range_name_cell').text(range);
        // also update data-range attribute on edit button
        row.find('.btn-edit').data('range', range).attr('data-range', range);
        // hide modal
        var modalEl = document.getElementById('editModal');
        var modal = bootstrap.Modal.getInstance(modalEl);
        modal.hide();
      } else {
        $('#editAlert').html('<div class="alert alert-danger">' + (resp.msg || 'Error') + '</div>');
      }
    }, 'json').fail(function() {
      $('#editAlert').html('<div class="alert alert-danger">Request failed</div>');
    });
  });
});
</script>
</body>
</html>
