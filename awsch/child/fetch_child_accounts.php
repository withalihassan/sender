<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../db.php';      // provides $pdo
require '../../session.php'; // starts session, provides $_SESSION

// ensure user is logged in
if (empty($_SESSION['user_id'])) {
    die('User not logged in.');
}
$sessionId = $_SESSION['user_id'];

// ensure parent_id is provided
if (empty($_GET['parent_id'])) {
    echo "<tr><td colspan='9' class='text-center'>Parent ID is missing.</td></tr>";
    exit;
}
$parentId = $_GET['parent_id'];

// fetch all child accounts for this parent (excluding self)
$sql = "
    SELECT *
      FROM child_accounts
     WHERE parent_id   = :pid
       AND account_id != :pid
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':pid' => $parentId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// if none, show message
if (empty($accounts)) {
    echo "<tr><td colspan='9' class='text-center'>No child accounts found.</td></tr>";
    exit;
}

// loop and output rows
foreach ($accounts as $i => $acct) {
    // Status badge
    switch (strtoupper($acct['status'] ?? '')) {
        case 'ACTIVE':
            $statusBadge = "<span class='badge bg-success'>Active</span>";
            break;
        case 'SUSPENDED':
            $statusBadge = "<span class='badge bg-danger'>Suspended</span>";
            break;
        default:
            $statusBadge = "<span class='badge bg-secondary'>Unknown</span>";
            break;
    }

    // compute age in days (0 if no date)
    if (!empty($acct['added_date'])) {
        $added = new DateTime($acct['added_date']);
        $now   = new DateTime();
        $days  = (int)$added->diff($now)->format('%a');
    } else {
        $days = 0;
    }

    // Age column badge
    if (empty($acct['added_date'])) {
        // status is NULL or empty
        $ageBadge = "<span class='badge bg-primary'>Fetch me</span>";
    } elseif ($acct['status'] == 'SUSPENDED') {
        $ageBadge = "<span class='badge bg-light'>❌</span>";
    } elseif ($days >= 6) {
        $ageBadge = "<span class='badge bg-success'>✅ Ready</span>";
    } else {
        $ageBadge = "<span class='badge bg-warning text-dark'>⏳ Pending</span>";
    }

    // highlight row if not in org
    $rowStyle = ($acct['is_in_org'] === 'No')
        ? "style='background-color: #ffcccc;'"
        : "";

    // print the row
    echo "<tr>";
    echo "  <td $rowStyle>" . ($i + 1) . "</td>";
    echo "  <td>" . htmlspecialchars($acct['name'])         . "</td>";
    echo "  <td>" . htmlspecialchars($acct['email'])        . "</td>";
    echo "  <td>{$statusBadge}</td>";
    echo "  <td>" . htmlspecialchars($acct['worth_type'])   . "</td>";
    echo "  <td>{$ageBadge}</td>";
    echo "  <td>" . htmlspecialchars($acct['account_id'])   . "</td>";
    echo "  <td>
            <div class='btn-group d-inline-flex' role='group'>
            <a href='./bulk_regional_send.php?ac_id=" . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-success btn-sm'>Full Sndr</a>
            <a href='./full_sender_v2.php?ac_id=" . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-info btn-sm'>V2</a>
            </div>
            <div class='btn-group d-inline-flex' role='group'>
            <a href='./brs.php?ac_id="               . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-success btn-sm'>Half Sndr</a>
            <a href='./half_sender_v2.php?ac_id="               . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-info btn-sm'>V2</a>
            </div>
          
            <a href='./enable_regions.php?ac_id="    . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-secondary btn-sm'>E-R</a>
            <a href='./clear_single.php?ac_id="      . urlencode($acct['account_id']) . "&parrent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-warning btn-sm'>Clear</a>
            <a href='child_account.php?child_id="    . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-primary btn-sm'>Setup</a>
            <a href='./chk_quality.php?ac_id="       . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "'         target='_blank' class='btn btn-warning btn-sm'>CHK-Q</a>
            <a href='./child_actions.php?ac_id="     . urlencode($acct['account_id']) . "&parent_id=" . urlencode($parentId) . "&user_id=" . urlencode($sessionId) . "' target='_blank' class='btn btn-success btn-sm'>Open</a>
          </td>";
    echo "</tr>";
}
