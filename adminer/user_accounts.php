<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
include('../db.php');

// AJAX Handling
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action == 'filter') {
        // Filter accounts based on from_date and to_date.
        $userId    = $_POST['user'];
        $from_date = $_POST['from_date'];
        $to_date   = $_POST['to_date'];
        
        if (!empty($from_date) && !empty($to_date)) {
            // Use a range that includes the full to_date day.
            $stmt = $pdo->prepare("SELECT * FROM accounts 
                                   WHERE by_user = ? 
                                     AND added_date >= ? 
                                     AND added_date < DATE_ADD(?, INTERVAL 1 DAY)
                                   ORDER BY added_date DESC");
            $stmt->execute([$userId, $from_date, $to_date]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM accounts 
                                   WHERE by_user = ? 
                                   ORDER BY added_date DESC");
            $stmt->execute([$userId]);
        }
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build table rows
        $html = "";
        foreach ($accounts as $account) {
            // If worth_type is not explicitly set to full or half, default to half.
            $worth_type = ($account['worth_type'] === 'full' || $account['worth_type'] === 'half')
                          ? $account['worth_type'] : 'half';
            $paymentStatus = isset($account['payment']) ? $account['payment'] : '';
            if ($paymentStatus == 'paid') {
                $paidButton = 'Paid';
            } else {
                $paidButton = '<button class="btn btn-success btn-sm mark-paid" data-account-id="' . $account['id'] . '">Paid</button>';
            }
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($account['id']) . "</td>";
            $html .= "<td>" . htmlspecialchars($account['aws_key']) . "</td>";
            $html .= "<td>" . htmlspecialchars($account['account_id']) . "</td>";
            $html .= "<td>" . htmlspecialchars($worth_type) . "</td>";
            $html .= "<td>" . htmlspecialchars(date('M d, Y', strtotime($account['added_date']))) . "</td>";
            $html .= "<td>" . $paidButton . "</td>";
            $html .= "</tr>";
        }
        echo $html;
        exit;
    }
    
    if ($action == 'mark_paid') {
        $accountId = $_POST['account_id'];
        $stmt = $pdo->prepare("UPDATE accounts SET payment = 'paid' WHERE id = ?");
        if ($stmt->execute([$accountId])) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }
}

// Main Page Load
$userId = $_GET['user'];
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found");
}
$username = $user['name'];

// Summary Queries
// Last 7 days half accounts (all non-full accounts are treated as half; cost = 70)
$sevenDaysHalfStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(70), 0) AS total_cost 
                                    FROM accounts 
                                    WHERE by_user = ? 
                                      AND added_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE() 
                                      AND (worth_type != 'full')");
$sevenDaysHalfStmt->execute([$userId]);
$sevenDaysHalf = $sevenDaysHalfStmt->fetch(PDO::FETCH_ASSOC);

// Last 7 days full accounts (cost = 100)
$sevenDaysFullStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(100), 0) AS total_cost 
                                    FROM accounts 
                                    WHERE by_user = ? 
                                      AND added_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE() 
                                      AND worth_type = 'full'");
$sevenDaysFullStmt->execute([$userId]);
$sevenDaysFull = $sevenDaysFullStmt->fetch(PDO::FETCH_ASSOC);

// All Time Paid Accounts: separate counts and costs for full and half.
$allTimePaidStmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN payment = 'paid' AND worth_type = 'full' THEN 1 ELSE 0 END) AS full_count,
        SUM(CASE WHEN payment = 'paid' AND worth_type = 'full' THEN 100 ELSE 0 END) AS full_cost,
        SUM(CASE WHEN payment = 'paid' AND (worth_type = 'half' OR worth_type <> 'full') THEN 1 ELSE 0 END) AS half_count,
        SUM(CASE WHEN payment = 'paid' AND (worth_type = 'half' OR worth_type <> 'full') THEN 70 ELSE 0 END) AS half_cost
    FROM accounts 
    WHERE by_user = ?
");
$allTimePaidStmt->execute([$userId]);
$allTimePaid = $allTimePaidStmt->fetch(PDO::FETCH_ASSOC);

// Initial Accounts List
$stmtAccounts = $pdo->prepare("SELECT * FROM accounts WHERE by_user = ? ORDER BY added_date DESC");
$stmtAccounts->execute([$userId]);
$accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Accounts - <?php echo htmlspecialchars($username); ?></title>
    <!-- Bootstrap & DataTables CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css">
    <style>
        .container { margin-top: 20px; }
        .summary-box { margin-bottom: 20px; }
        .summary-box .card { margin-bottom: 10px; }
    </style>
</head>
<body>
    <?php include "./header.php"; ?>

    <div class="container-fluid">
        <h2>User Accounts for <?php echo htmlspecialchars($username); ?></h2>
        
        <!-- Summary Box -->
        <div class="row summary-box">
            <div class="col-md-4">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h5 class="card-title">Last 7 Days Half Accounts</h5>
                        <p class="card-text">
                            Count: <?php echo $sevenDaysHalf['cnt']; ?>, 
                            Cost: $<?php echo $sevenDaysHalf['total_cost']; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title">Last 7 Days Full Accounts</h5>
                        <p class="card-text">
                            Count: <?php echo $sevenDaysFull['cnt']; ?>, 
                            Cost: $<?php echo $sevenDaysFull['total_cost']; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-secondary">
                    <div class="card-body">
                        <h5 class="card-title">All Time Paid Accounts</h5>
                        <p class="card-text">
                            Full: <?php echo $allTimePaid['full_count']; ?> ($<?php echo $allTimePaid['full_cost']; ?>)<br>
                            Half: <?php echo $allTimePaid['half_count']; ?> ($<?php echo $allTimePaid['half_cost']; ?>)
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Date Filter Form -->
        <div class="row mb-3">
            <div class="col-md-3">
                <input type="date" id="from_date" class="form-control" placeholder="From Date">
            </div>
            <div class="col-md-3">
                <input type="date" id="to_date" class="form-control" placeholder="To Date">
            </div>
            <div class="col-md-3">
                <button id="filterBtn" class="btn btn-primary">Filter</button>
            </div>
        </div>
        
        <!-- Accounts Table -->
        <table id="accountsTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>AWS Key</th>
                    <th>Account ID</th>
                    <th>Type</th>
                    <th>Added Date</th>
                    <th>Payment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account):
                    $worth_type = ($account['worth_type'] === 'full' || $account['worth_type'] === 'half') ? $account['worth_type'] : 'half';
                    $paymentStatus = isset($account['payment']) ? $account['payment'] : '';
                    if ($paymentStatus == 'paid') {
                        $paidButton = 'Payment Paid';
                    } else {
                        $paidButton = '<button class="btn btn-success btn-sm mark-paid" data-account-id="' . $account['id'] . '">Mark it Paid</button>';
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($account['id']); ?></td>
                    <td><?php echo htmlspecialchars($account['aws_key']); ?></td>
                    <td><?php echo htmlspecialchars($account['account_id']); ?></td>
                    <td><?php echo htmlspecialchars($worth_type); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y', strtotime($account['added_date']))); ?></td>
                    <td><?php echo htmlspecialchars($account['account_id']); ?></td>
                    <td><?php echo $paidButton; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- jQuery, DataTables, Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
    
    <script>
    $(document).ready(function(){
        var table = $('#accountsTable').DataTable({
            "pageLength": 10
        });
        
        // Filter accounts by date via AJAX
        $('#filterBtn').on('click', function(){
            var from_date = $('#from_date').val();
            var to_date   = $('#to_date').val();
            var user      = "<?php echo $userId; ?>";
            $.ajax({
                url: "", // same page
                type: "POST",
                data: { action: 'filter', from_date: from_date, to_date: to_date, user: user },
                success: function(data){
                    $('#accountsTable tbody').html(data);
                    table.rows().invalidate().draw();
                }
            });
        });
        
        // Mark an account as paid via AJAX
        $('#accountsTable').on('click', '.mark-paid', function(){
            var button = $(this);
            var accountId = button.data('account-id');
            $.ajax({
                url: "",
                type: "POST",
                data: { action: 'mark_paid', account_id: accountId },
                dataType: "json",
                success: function(response){
                    if(response.status === 'success'){
                        button.replaceWith('Paid');
                    } else {
                        alert('Error updating payment status.');
                    }
                }
            });
        });
    });
    </script>
</body>
</html>
