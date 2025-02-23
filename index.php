
<?php
include  "./session.php";
// index.php
include "./header.php";
// Include your database connection file
include('db.php');

// Include the AWS PHP SDK autoloader (ensure the path is correct)
require './aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

// Initialize message variable for response messages
$message = "";

if (isset($_POST['submit'])) {
    // Trim and fetch the AWS credentials from the form
    $aws_key    = trim($_POST['aws_key']);
    $aws_secret = trim($_POST['aws_secret']);

    // Check if fields are not empty
    if (empty($aws_key) || empty($aws_secret)) {
        $message = "AWS Key and Secret cannot be empty.";
    } else {
        try {
            // Create an STS client with the provided credentials
            $stsClient = new StsClient([
                'version'     => 'latest',
                'region'      => 'us-east-1', // change to your desired region
                'credentials' => [
                    'key'    => $aws_key,
                    'secret' => $aws_secret,
                ]
            ]);

            // Call getCallerIdentity to fetch the AWS Account ID
            $result     = $stsClient->getCallerIdentity();
            $account_id = $result->get('Account');

            // Use current timestamp for the added_date field
            $added_date = date("Y-m-d H:i:s");

            // Insert the account into the database with default status "active"
            $stmt = $pdo->prepare("INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, added_date) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$session_id, $aws_key, $aws_secret, $account_id, 'active', $added_date])) {
                $message = "Account added successfully. AWS Account ID: " . htmlspecialchars($account_id);
            } else {
                $message = "Failed to insert account into the database.";
            }
        } catch (AwsException $e) {
            // Catch errors thrown by AWS SDK
            $message = "AWS Error: " . $e->getAwsErrorMessage();
        } catch (Exception $e) {
            // Catch any other errors
            $message = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Home- Add AWS Accounts</title>
  <!-- Bootstrap CSS for styling -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
  <!-- DataTables CSS for pagination -->
  <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
  <!-- jQuery -->
  <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
  <!-- DataTables JS -->
  <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.js"></script>
</head>
<body>
<div class="container-fluid" style="padding: 4%;">
  <h2 class="mt-4">Add AWS Account</h2>
  <?php if (!empty($message)) { echo '<div class="alert alert-info">' . $message . '</div>'; } ?>
  <form method="post" action="">
    <div class="form-group">
      <label for="aws_key">AWS Key:</label>
      <input type="text" name="aws_key" id="aws_key" class="form-control" required>
    </div>
    <div class="form-group">
      <label for="aws_secret">AWS Secret:</label>
      <input type="text" name="aws_secret" id="aws_secret" class="form-control" required>
    </div>
    <button type="submit" name="submit" class="btn btn-primary">Add Account</button>
  </form>

  <hr>
  <h2>Accounts List</h2>
  <table id="accountsTable" class="display">
    <thead>
      <tr>
        <th>ID</th>
        <th>AWS Key</th>
        <th>Account ID</th>
        <th>Status</th>
        <th>Added Date</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    // Fetch all accounts from the database and display them
    $stmt = $pdo->query("SELECT * FROM accounts WHERE by_user = '$session_id'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
          echo "<td>" . $row['id'] . "</td>";
          echo "<td>" . htmlspecialchars($row['aws_key']) . "</td>";
          echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
          echo "<td>" . htmlspecialchars($row['status']) . "</td>";
          echo "<td>" . $row['added_date'] . "</td>";
          echo "<td>
                  <button class='btn btn-danger btn-sm delete-btn' data-id='" . $row['id'] . "'>Delete</button>
                  <button class='btn btn-info btn-sm check-status-btn' data-id='" . $row['id'] . "'>Check Status</button>
                  <a href='bulk_send.php?ac_id=" . $row['id'] . "&user_id=".$session_id."' target='_blank' class='btn btn-secondary btn-sm'>Bulk Send</a>
                  <a href='bulk_regional_send.php?ac_id=" . $row['id'] . "&user_id=".$session_id."' target='_blank' class='btn btn-success btn-sm'>Bulk Regional Send</a>
                  <a href='brs.php?ac_id=" . $row['id'] . "&user_id=".$session_id."' target='_blank' class='btn btn-success btn-sm'>BRS</a>
                  <a href='aws_account.php?id=" . $row['id'] . "' target='_blank' class='btn btn-primary btn-sm'>EnableReg</a>
                  <a href='nodesender/sender.php?id=" . $row['id'] . "' target='_blank' class='btn btn-primary btn-sm'>NodeSender</a>
                  <a href='clear_region.php?ac_id=" . $row['id'] . "' target='_blank' class='btn btn-primary btn-sm'>Clear</a>
                </td>";
        echo "</tr>";
    }
    ?>
    </tbody>
  </table>
</div>

<script>
$(document).ready(function () {
    // Initialize DataTables with pagination
    $('#accountsTable').DataTable();

    // Delete account event handler
    $('.delete-btn').click(function () {
        if (confirm("Are you sure you want to delete this account?")) {
            var id = $(this).data('id');
            $.ajax({
                url: './scripts/delete_account.php',
                type: 'POST',
                data: { id: id },
                success: function (response) {
                    alert(response);
                    location.reload();
                },
                error: function() {
                    alert("An error occurred while deleting the account.");
                }
            });
        }
    });

    // Check account status event handler
    $('.check-status-btn').click(function () {
        var id = $(this).data('id');
        $.ajax({
            url: './scripts/check_status.php',
            type: 'POST',
            data: { id: id },
            success: function (response) {
                alert(response);
                location.reload();
            },
            error: function() {
                alert("An error occurred while checking the account status.");
            }
        });
    });
});
</script>
</body>
</html>
