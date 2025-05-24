<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
include('../db.php');

$response_message = "";

// Process delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $userId = $_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt->execute([$userId])) {
        $response_message = "User deleted successfully!";
    } else {
        $response_message = "Error deleting user!";
    }
}

// Process toggle status action (active ↔ onhold)
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    $userId = $_GET['id'];
    // Get the current status
    $stmt = $pdo->prepare("SELECT account_status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userData) {
        $currentStatus = $userData['account_status'];
        $newStatus = ($currentStatus === 'active') ? 'onhold' : 'active';
        $stmtUpdate = $pdo->prepare("UPDATE users SET account_status = ? WHERE id = ?");
        if ($stmtUpdate->execute([$newStatus, $userId])) {
            $response_message = "User status updated successfully!";
        } else {
            $response_message = "Error updating user status!";
        }
    } else {
        $response_message = "User not found!";
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT * FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css">
    <style>
        .container { margin-top: 20px; }
        .table thead th { background-color: #007bff; color: #fff; }
    </style>
</head>
<body>
    <?php include "./header.php"; ?>

    <div class="container-fluid p-5">
        <?php if (!empty($response_message)): ?>
            <div class="alert alert-info">
                <?php echo htmlspecialchars($response_message); ?>
            </div>
        <?php endif; ?>

        <h2 class="mb-4">Users &amp; Account Costs</h2>
        <table id="usersTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Account Status</th>
                    <th>Type</th>
                    <th>Created At</th>
                    <th>Total Accounts</th>
                    <th>Total Cost (Rs)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    // Separate query to get the total accounts and cost for the user
                    $stmtAcc = $pdo->prepare("SELECT COUNT(*) AS total_accounts, 
                                                   COALESCE(SUM(CASE 
                                                            WHEN worth_type = 'full' THEN 100 
                                                            WHEN worth_type = 'half' THEN 70 
                                                            ELSE 0 
                                                          END),0) AS total_cost 
                                              FROM accounts 
                                              WHERE by_user = ?");
                    $stmtAcc->execute([$user['id']]);
                    $accountData = $stmtAcc->fetch(PDO::FETCH_ASSOC);

                    // Format the created_at date nicely
                    $date = new DateTime($user['created_at']);
                    $formattedDate = $date->format('M d, Y');

                    // Determine the toggle button label based on current account status
                    $toggleButtonText = ($user['account_status'] === 'active') ? 'Put on Hold' : 'Activate';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['account_status']); ?></td>
                        <td><?php echo htmlspecialchars($user['type']); ?></td>
                        <td><?php echo htmlspecialchars($formattedDate); ?></td>
                        <td><?php echo htmlspecialchars($accountData['total_accounts']); ?></td>
                        <td><?php echo htmlspecialchars($accountData['total_cost']); ?></td>
                        <td>
                            <a href="user_accounts.php?user=<?php echo urlencode($user['id']); ?>"  target='_blank' class="btn btn-info btn-sm mb-1">View Details</a>
                            <a href="?action=delete&id=<?php echo $user['id']; ?>" class="btn btn-danger btn-sm mb-1" onclick="return confirm('Are you sure you want to delete this user?');">Delete</a>
                            <a href="?action=toggle&id=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm mb-1"><?php echo $toggleButtonText; ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- jQuery, DataTables and Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#usersTable').DataTable({
                "pageLength": 10
            });
        });
    </script>
</body>
</html>
