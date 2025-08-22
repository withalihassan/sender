<?php
// import_numbers.php
// Cleaned-up version of your original file.
// Features: bulk manual import (no '+' added), per-set filtering (use ?id=<set_id>),
// single delete, bulk delete (for selected set or all), send OTP (decrement atm_left),
// stats and improved UI.

include __DIR__ . "/../session.php"; // ensure this sets $_SESSION['user_id']
require __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($session_id <= 0) {
    die('Access denied. Please sign in.');
}

$message = '';
$selected_set_id = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk Import (manual textarea) - DO NOT add '+' automatically
    if (isset($_POST['import_numbers'])) {
        $manual = isset($_POST['manual_numbers']) ? trim($_POST['manual_numbers']) : '';
        $set_id = isset($_POST['set_id']) && $_POST['set_id'] !== '' ? (int)$_POST['set_id'] : null;

        if ($manual === '' || $set_id === null) {
            $message = 'Please choose a set and paste numbers (one per line).';
        } else {
            $lines = preg_split('/\r?\n/', $manual);
            $imported = 0;
            $skipped = 0;

            $stmtCheck = $pdo->prepare("SELECT id FROM allowed_numbers WHERE phone_number = ? AND by_user = ?");
            $stmtInsert = $pdo->prepare("INSERT INTO allowed_numbers (phone_number, status, atm_left, by_user, set_id, created_at) VALUES (?, 'fresh', 10, ?, ?, ?)");

            $tz = new DateTimeZone('Asia/Karachi');
            $created_at = (new DateTime('now', $tz))->format('Y-m-d H:i:s');

            foreach ($lines as $line) {
                $num = trim($line);
                if ($num === '') continue;

                // Basic normalization: remove spaces and common separators (but DO NOT add '+')
                $num = preg_replace('/[\s\-()\.]/', '', $num);

                // Optional: skip obviously invalid numbers
                if (strlen($num) < 6) { // naive check
                    $skipped++;
                    continue;
                }

                // Skip duplicate numbers for this user (same number regardless of set)
                $stmtCheck->execute([$num, $session_id]);
                if ($stmtCheck->rowCount() > 0) {
                    $skipped++;
                    continue;
                }

                try {
                    $stmtInsert->execute([$num, $session_id, $set_id, $created_at]);
                    $imported++;
                } catch (PDOException $e) {
                    // skip on error
                    $skipped++;
                }
            }

            $message = "Imported $imported numbers.";
            if ($skipped > 0) $message .= " Skipped $skipped entries.";

            // if a set filter is not active, set selected_set_id to the set that was used so user sees imported items
            if (!$selected_set_id) $selected_set_id = $set_id;
        }
    }

    // Delete single number
    if (isset($_POST['delete_number'])) {
        $delete_id = (int)$_POST['delete_number'];
        $stmt = $pdo->prepare("DELETE FROM allowed_numbers WHERE id = ? AND by_user = ?");
        try {
            $stmt->execute([$delete_id, $session_id]);
            $message = 'Number deleted.';
        } catch (PDOException $e) {
            $message = 'Error deleting number: ' . htmlspecialchars($e->getMessage());
        }
    }

    // Bulk delete (for selected set, or if none selected - delete all for user)
    if (isset($_POST['delete_all'])) {
        try {
            if ($selected_set_id) {
                $stmt = $pdo->prepare("DELETE FROM allowed_numbers WHERE by_user = ? AND set_id = ?");
                $stmt->execute([$session_id, $selected_set_id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM allowed_numbers WHERE by_user = ?");
                $stmt->execute([$session_id]);
            }
            $deleted = $stmt->rowCount();
            $message = "Bulk deleted $deleted numbers.";
        } catch (PDOException $e) {
            $message = 'Error bulk deleting: ' . htmlspecialchars($e->getMessage());
        }
    }

    // Send OTP -> decrement atm_left and update status/last_used
    if (isset($_POST['send_otp'])) {
        $nid = (int)$_POST['send_otp'];
        try {
            // Start a transaction to avoid race conditions
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ? AND by_user = ? FOR UPDATE");
            $stmt->execute([$nid, $session_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $message = 'Number not found.';
                $pdo->rollBack();
            } else {
                $atm_left = (int)$row['atm_left'];
                if ($atm_left <= 0) {
                    $message = 'No attempts left for this number.';
                    $pdo->rollBack();
                } else {
                    $new_atm = $atm_left - 1;
                    $new_status = $new_atm === 0 ? 'used' : 'fresh';
                    $stmtUpd = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, status = ?, last_used = NOW() WHERE id = ? AND by_user = ?");
                    $stmtUpd->execute([$new_atm, $new_status, $nid, $session_id]);
                    $pdo->commit();
                    $message = 'OTP sent (simulated). Remaining attempts: ' . $new_atm;
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = 'Error sending OTP: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// --- Fetch stats ---
try {
    if ($selected_set_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'fresh' AND by_user = ? AND set_id = ?");
        $stmt->execute([$session_id, $selected_set_id]);
        $freshCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'used' AND by_user = ? AND set_id = ?");
        $stmt->execute([$session_id, $selected_set_id]);
        $usedNumbers = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE by_user = ? AND set_id = ?");
        $stmt->execute([$session_id, $selected_set_id]);
        $totalNumbers = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT IFNULL(SUM(10 - atm_left),0) FROM allowed_numbers WHERE by_user = ? AND DATE(last_used) = CURDATE() AND set_id = ?");
        $stmt->execute([$session_id, $selected_set_id]);
        $todayOTPs = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT IFNULL(SUM(atm_left),0) FROM allowed_numbers WHERE by_user = ? AND set_id = ?");
        $stmt->execute([$session_id, $selected_set_id]);
        $totalAtmLeft = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'fresh' AND by_user = ?");
        $stmt->execute([$session_id]);
        $freshCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'used' AND by_user = ?");
        $stmt->execute([$session_id]);
        $usedNumbers = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE by_user = ?");
        $stmt->execute([$session_id]);
        $totalNumbers = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT IFNULL(SUM(10 - atm_left),0) FROM allowed_numbers WHERE by_user = ? AND DATE(last_used) = CURDATE()");
        $stmt->execute([$session_id]);
        $todayOTPs = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT IFNULL(SUM(atm_left),0) FROM allowed_numbers WHERE by_user = ?");
        $stmt->execute([$session_id]);
        $totalAtmLeft = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    $message = 'Error fetching stats: ' . htmlspecialchars($e->getMessage());
    $freshCount = $usedNumbers = $totalNumbers = $todayOTPs = $totalAtmLeft = 0;
}

// --- Fetch numbers list ---
if ($selected_set_id) {
    $stmt = $pdo->prepare("SELECT an.*, bs.set_name FROM allowed_numbers an LEFT JOIN bulk_sets bs ON an.set_id = bs.id WHERE an.set_id = ? AND an.by_user = ? ORDER BY an.id DESC");
    $stmt->execute([$selected_set_id, $session_id]);
} else {
    $stmt = $pdo->prepare("SELECT an.*, bs.set_name FROM allowed_numbers an LEFT JOIN bulk_sets bs ON an.set_id = bs.id WHERE an.by_user = ? ORDER BY an.id DESC");
    $stmt->execute([$session_id]);
}
$numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available sets for dropdown
$setsStmt = $pdo->prepare("SELECT id, set_name FROM bulk_sets WHERE by_user = ? AND status = 'receiver' ORDER BY set_name ASC");
$setsStmt->execute([$session_id]);
$sets = $setsStmt->fetchAll(PDO::FETCH_ASSOC);

$currentDateTime = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('l, F j, Y, g:i A');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Numbers</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css">
    <style>
        body { background: #f5f7fa; }
        .stats-card { border-radius: 8px; padding: 15px; color: #fff; }
        .stats-card h5 { margin: 0 0 8px 0; }
        .card-fresh { background: #28a745; }
        .card-used { background: #dc3545; }
        .card-total { background: #17a2b8; }
        .card-today { background: #ffc107; color: #000; }
        .card-atm { background: #6f42c1; }
        .small-note { font-size: 0.9rem; color: #666; }
        textarea { font-family: monospace; }
    </style>
</head>
<body>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Import Numbers</h3>
        <div class="small-note"><?php echo htmlspecialchars($currentDateTime); ?></div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Import Form (collapsible) -->
    <div class="mb-4">
        <button class="btn btn-primary mb-2" type="button" data-toggle="collapse" data-target="#importCollapse" aria-expanded="false">Toggle Import Form</button>
        <div class="collapse" id="importCollapse">
            <div class="card card-body">
                <form method="POST">
                    <div class="form-row align-items-center">
                        <div class="col-auto">
                            <label for="set_id">Select Set</label>
                            <select name="set_id" id="set_id" class="form-control form-control-sm">
                                <option value="">Choose set</option>
                                <?php foreach ($sets as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>" <?php echo ($selected_set_id && $selected_set_id == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['set_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <label for="manual_numbers">Paste numbers (one per line)</label>
                            <textarea name="manual_numbers" id="manual_numbers" rows="5" class="form-control" placeholder="e.g. 923001234567\n03001234567"></textarea>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" name="import_numbers" class="btn btn-success">Import Numbers</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Delete -->
    <form method="POST" onsubmit="return confirm('Are you sure you want to bulk delete the numbers?');" class="mb-3">
        <button type="submit" name="delete_all" class="btn btn-danger">Bulk Delete <?php echo $selected_set_id ? 'Selected Set' : 'All'; ?></button>
    </form>

    <!-- Filter by set quick links -->
    <div class="mb-3">
        <a href="?" class="btn btn-sm btn-outline-secondary<?php echo $selected_set_id ? '' : ' active'; ?>">All</a>
        <?php foreach ($sets as $s): ?>
            <a href="?id=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-outline-secondary<?php echo ($selected_set_id == $s['id']) ? ' active' : ''; ?>"><?php echo htmlspecialchars($s['set_name']); ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Numbers table -->
    <div class="table-responsive">
        <table id="numbersTable" class="table table-striped table-bordered">
            <thead>
            <tr>
                <th>ID</th>
                <th>Phone</th>
                <th>Set</th>
                <th>ATM Left</th>
                <th>Status</th>
                <th>Last Used</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($numbers)): ?>
                <?php foreach ($numbers as $n): ?>
                    <tr>
                        <td><?php echo (int)$n['id']; ?></td>
                        <td><?php echo htmlspecialchars($n['phone_number']); ?></td>
                        <td><?php echo htmlspecialchars($n['set_name'] ?? '—'); ?></td>
                        <td><?php echo (int)$n['atm_left']; ?></td>
                        <td><?php echo htmlspecialchars($n['status']); ?></td>
                        <td><?php echo $n['last_used'] ? htmlspecialchars($n['last_used']) : '—'; ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <button type="submit" name="delete_number" value="<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this number?');">Delete</button>
                            </form>

                            <?php if ($n['atm_left'] > 0 && $n['status'] !== 'used'): ?>
                                <form method="POST" style="display:inline;">
                                    <button type="submit" name="send_otp" value="<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-primary" onclick="return confirm('Send OTP to this number?');">Send OTP</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="text-center">No numbers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#numbersTable').DataTable({
            "order": [[0, "desc"]],
            "pageLength": 25
        });
    });
</script>
</body>
</html>
