<?php
// index.php - Simple single-file UI + endpoints for bulk_sets
// Place this file in your web folder. Make sure ../db.php returns a PDO instance in $pdo.

require_once __DIR__ . '/../db.php'; // adjust if your db.php is elsewhere
include "../session.php";
// Simple router for AJAX actions
$action = $_GET['action'] ?? '';
if ($action === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->prepare("SELECT id, set_name, description, created_at, status FROM bulk_sets WHERE status = :status ORDER BY id DESC");
        $stmt->execute([':status' => 'receiver']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $set_name = trim($_POST['set_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = 'receiver';
    if ($set_name === '' || $description === '') {
        echo json_encode(['success' => false, 'error' => 'Missing fields']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO bulk_sets (by_user, set_name, description, created_at, status) VALUES (:by_user, :set_name, :description, NOW(), :status)");
        $stmt->execute([':by_user' => $session_id, ':set_name' => $set_name, ':description' => $description, ':status' => $status]);
        $lastId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $lastId]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid id']); exit; }
    try {
        $stmt = $pdo->prepare("DELETE FROM bulk_sets WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Code-x — Simple SMS Receiver Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f8f9fa}.logo{font-weight:700}.card-right{position:sticky;top:1rem}</style>
  </head>
  <body>
    <div class="container py-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <div class="logo h4 mb-0 text-primary">Code-x</div>
          <div class="text-muted">A simple platform to receive SMS for verification.</div>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-8 mb-4">
          <div class="card">
            <div class="card-header"><strong>Sets (status = receiver)</strong></div>
            <div class="card-body p-0">
              <table class="table table-striped mb-0" id="setsTable">
                <thead><tr><th>ID</th><th>Set name</th><th>Description</th><th>Created</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card card-right">
            <div class="card-header"><strong>Add Set (status=receiver)</strong></div>
            <div class="card-body">
              <form id="addForm">
                <div class="mb-3"><label class="form-label">Set name</label><input class="form-control" name="set_name" required></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="4" required></textarea></div>
                <div class="d-grid"><button class="btn btn-primary" type="submit">Submit</button></div>
              </form>
              <div id="msg" class="mt-2"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script>
      // helper to show messages
      function showMsg(text, cls='alert-success'){
        const el = document.getElementById('msg');
        el.innerHTML = '<div class="alert '+cls+'">'+text+'</div>';
        setTimeout(()=>el.innerHTML='', 3500);
      }

      // load data and render table
      async function loadSets(){
        const res = await fetch('?action=fetch');
        const obj = await res.json();
        if (!obj.success){ showMsg('Load failed','alert-danger'); return; }
        const tbody = document.querySelector('#setsTable tbody');
        tbody.innerHTML = '';
        obj.data.forEach(row=>{
          const tr = document.createElement('tr');
          tr.innerHTML = '<td>'+row.id+'</td>'+
                         '<td>'+escapeHtml(row.set_name)+'</td>'+
                         '<td>'+escapeHtml(row.description)+'</td>'+
                         '<td>'+row.created_at+'</td>'+
                         '<td>'+row.status+'</td>'+
                         '<td>'+
                           '<a class="btn btn-sm btn-outline-success me-1" target="_blank" href="receiver.php?set_id='+row.id+'">Receiver</a>'+
                           '<a class="btn btn-sm btn-outline-primary me-1" target="_blank" href="importer.php?id='+row.id+'">Importer</a>'+
                           '<button data-id="'+row.id+'" class="btn btn-sm btn-outline-danger btn-del">Delete</button>'+
                         '</td>';
          tbody.appendChild(tr);
        });
        // add delete listeners
        document.querySelectorAll('.btn-del').forEach(b=> b.onclick = deleteSet);
      }

      function escapeHtml(unsafe){ return unsafe
        .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }

      async function deleteSet(e){
        const id = this.dataset.id;
        if(!confirm('Delete ID '+id+' ?')) return;
        const form = new FormData(); form.append('id', id);
        const res = await fetch('?action=delete', { method:'POST', body: form });
        const obj = await res.json();
        if (obj.success) { showMsg('Deleted ID '+id); loadSets(); }
        else showMsg('Delete failed','alert-danger');
      }

      // handle add form
      document.getElementById('addForm').addEventListener('submit', async function(e){
        e.preventDefault();
        const formData = new FormData(this);
        const res = await fetch('?action=add', { method: 'POST', body: formData });
        const obj = await res.json();
        if (obj.success){ this.reset(); showMsg('Added ID '+obj.id); loadSets(); }
        else showMsg('Add failed: '+(obj.error||'server error'),'alert-danger');
      });

      // initial load
      loadSets();
    </script>
  </body>
</html>
