<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
include('../db.php');
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $account_status = trim($_POST['account_status']);

    // Insert the new user (password stored as plain text per requirements)
    $stmt = $pdo->prepare("INSERT INTO users (username, password, account_status) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$username, $password, $account_status]);
        $message = "User added successfully!";
    } catch (PDOException $e) {
        $message = "Error adding user: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css">
    <style>
        .container {
            margin-top: 50px;
        }

        .btn-group .status-select {
            margin-right: 5px;
        }
    </style>
</head>

<body>
    <?php include "./header.php"; ?>
    <div class="container">
        <h1>Welcome To Admin Panel</h1>
    </div>

    <!-- jQuery, Bootstrap JS, and DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#usersTable').DataTable();

            // Delete user with AJAX
            $('.delete-user').on('click', function() {
                if (confirm("Are you sure you want to delete this user?")) {
                    var userId = $(this).data('id');
                    var row = $('#row-' + userId);
                    $.ajax({
                        url: 'scripts/delete_user.php',
                        type: 'POST',
                        data: {
                            id: userId
                        },
                        success: function(response) {
                            if (response === 'success') {
                                row.fadeOut(500, function() {
                                    row.remove();
                                });
                            } else {
                                alert('Error deleting user.');
                            }
                        }
                    });
                }
            });

            // Update user status with AJAX
            $('.update-status').on('click', function() {
                var userId = $(this).data('id');
                var newStatus = $(this).siblings('.status-select').val();
                $.ajax({
                    url: 'scripts/update_status.php',
                    type: 'POST',
                    data: {
                        id: userId,
                        status: newStatus
                    },
                    success: function(response) {
                        if (response === 'success') {
                            alert('Status updated successfully.');
                            // Optionally update the "Current Status" cell in the table:
                            $('#row-' + userId + ' td:nth-child(4)').text(newStatus);
                        } else {
                            alert('Error updating status: ' + response);
                        }
                    }
                });
            });
        });
    </script>
</body>

</html>