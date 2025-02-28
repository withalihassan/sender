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
    <title>Home- Claim and Start Process</title>
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
        <h2 class="mt-4">Claim Accounts</h2>
        <table id="accountsTable" class="display">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Account ID</th>
                    <th>AWS Key</th>
                    <th>Status</th>
                    <th>State</th>
                    <th>Account Score</th>
                    <th>Account Age</th>
                    <th>Credit Offset</th>
                    <th>Added Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch all accounts from the database and display them
                $stmt = $pdo->query("SELECT * FROM accounts WHERE ac_state = 'orphan' ORDER  by 1 DESC");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    echo "<tr>";
                    echo "<td>" . $row['id'] . "</td>";
                    echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['aws_key']) . "</td>";
                    // echo "<td>" . htmlspecialchars() . "</td>";
                    // echo "<td>" . htmlspecialchars($row['ac_state']) . "</td>";///
                ?>
                    <?php
                    //Account Statuus , Active or suspended
                    if ($row['status'] == 'active') {
                        echo "<td><span class='badge badge-success'>Active</span></td>";
                    } else {
                        echo "<td><span class='badge badge-danger'>Suspended</span></td>";
                    }
                    /// Account State,  > Orphan , Claimed , Rejected
                    if ($row['ac_state'] == 'orphan') {
                        echo "<td><span class='badge badge-warning'>Orphan</span></td>";
                    } else if ($row['ac_state'] == 'claimed') {
                        echo "<td><span class='badge badge-success'>Claimed</span></td>";
                    } else {
                        echo "<td><span class='badge badge-danger'>Rejected</span></td>";
                    }
                    //Account score like how many time We send a  otp on it
                    echo "<td>" . htmlspecialchars($row['ac_score']) . "</td>";

                    //Account age is  suspended theen differeenace betqween suspended_datee and Added_date otheerwise  current date and Added  date
                    if ($row['status'] == 'active') {
                        $td_Added_date = new DateTime($row['added_date']);
                        $td_current_date = new DateTime(); // current date and time

                        $diff = $td_Added_date->diff($td_current_date);
                        echo "<td>" . $diff->format('%a days') . "</td>";
                    } else {
                        //Means if suspended then calculate age based  on suspended date
                        $td_Added_date = new DateTime($row['added_date']);
                        $td_current_date = new DateTime($row['suspended_date']); // current date and time

                        $diff = $td_Added_date->diff($td_current_date);
                        echo "<td>" . $diff->format('%a days') . "</td>";
                    }
                    ?>
                <?php
                    // echo "<td>" . htmlspecialchars($row['ac_age']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['cr_offset']) . "</td>";
                    // echo "<td>" . (new DateTime($row['added_date']))->format('d M') . "</td>";
                    echo "<td>" . (new DateTime($row['added_date'], new DateTimeZone('Asia/Karachi')))->format('d M g:i a') . "</td>";

                    echo "<td>
                            <button class='btn btn-info btn-sm check-status-btn' data-id='" . $row['id'] . "'>Chk-Status</button>
                            <button class='btn btn-success btn-sm claim-btn' data-id='" . $row['id'] . "' >Claim</button>
                            <a href='check_quality.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank' class='btn btn-secondary btn-sm'>Chk-Qlty</a>
                            <a href='aws_account.php?id=" . $row['id'] . "' target='_blank' class='btn btn-primary btn-sm'>En-Reg</a>  
                            <a href='bulk_regional_send.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank' class='btn btn-success btn-sm'>BRS</a>
                            <a href='clear_region.php?ac_id=" . $row['id'] . "' target='_blank' class='btn btn-primary btn-sm'>Clear</a>
                        </td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
        <hr>
        <h2>Accounts List</h2>
        <table id="myaccountsTable" class="display">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Account ID</th>
                    <th>AWS Key</th>
                    <th>Status</th>
                    <th>State</th>
                    <th>Account Score</th>
                    <th>Account Age</th>
                    <th>Credit Offset</th>
                    <th>Added Date</th>
                    <th>Last Used</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch all accounts from the database and display them
                $stmt = $pdo->query("SELECT * FROM accounts WHERE ac_state = 'claimed' AND claimed_by = '$session_id' ORDER  by 1 DESC");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    echo "<tr>";
                    echo "<td>" . $row['id'] . "</td>";
                    echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['aws_key']) . "</td>";
                    // echo "<td>" . htmlspecialchars() . "</td>";
                    // echo "<td>" . htmlspecialchars($row['ac_state']) . "</td>";///
                ?>
                    <?php
                    //Account Statuus , Active or suspended
                    if ($row['status'] == 'active') {
                        echo "<td><span class='badge badge-success'>Active</span></td>";
                    } else {
                        echo "<td><span class='badge badge-danger'>Suspended</span></td>";
                    }
                    /// Account State,  > Orphan , Claimed , Rejected
                    if ($row['ac_state'] == 'orphan') {
                        echo "<td><span class='badge badge-warning'>Orphan</span></td>";
                    } else if ($row['ac_state'] == 'claimed') {
                        echo "<td><span class='badge badge-success'>Claimed</span></td>";
                    } else {
                        echo "<td><span class='badge badge-danger'>Rejected</span></td>";
                    }
                    //Account score like how many time We send a  otp on it
                    echo "<td>" . htmlspecialchars($row['ac_score']) . "</td>";

                    //Account age is  suspended theen differeenace betqween suspended_datee and Added_date otheerwise  current date and Added  date
                    if ($row['status'] == 'active') {
                        $td_Added_date = new DateTime($row['added_date']);
                        $td_current_date = new DateTime(); // current date and time

                        $diff = $td_Added_date->diff($td_current_date);
                        echo "<td>" . $diff->format('%a days') . "</td>";
                    } else {
                        //Means if suspended then calculate age based  on suspended date
                        $td_Added_date = new DateTime($row['added_date']);
                        $td_current_date = new DateTime($row['suspended_date']); // current date and time

                        $diff = $td_Added_date->diff($td_current_date);
                        echo "<td>" . $diff->format('%a days') . "</td>";
                    }
                    ?>
                <?php
                    // echo "<td>" . htmlspecialchars($row['ac_age']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['cr_offset']) . "</td>";
                    // echo "<td>" . (new DateTime($row['added_date']))->format('d M') . "</td>";
                    // echo "<td>" . (new DateTime($row['added_date'], new DateTimeZone('Asia/Karachi')))->format('d M g:i a') . "</td>";
                    // echo "<td>" . (new DateTime($row['added_date'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('d M g:i a') . "</td>";
                    echo "<td>" . (new DateTime($row['added_date']))->format('d M g:i a') . "</td>";
                    echo "<td>" . (new DateTime($row['last_used']))->format('d M g:i a') . "</td>";


                
                    echo "<td>
                  <button class='btn btn-info btn-sm check-status-btn' data-id='" . $row['id'] . "'>Check Status</button>
                  <a href='bulk_send.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank' class='btn btn-secondary btn-sm'>Bulk Send</a>
                  <a href='bulk_regional_send.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank' class='btn btn-success btn-sm'>Bulk Regional Send</a>
                  <a href='brs.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank' class='btn btn-success btn-sm'>BRS</a>
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
        $(document).ready(function() {
            // Initialize DataTables with pagination
            $('#accountsTable').DataTable();
            // Initialize DataTables with pagination
            $('#myaccountsTable').DataTable();

            // Delete account event handler
            $('.delete-btn').click(function() {
                if (confirm("Are you sure you want to delete this account?")) {
                    var id = $(this).data('id');
                    $.ajax({
                        url: './scripts/delete_account.php',
                        type: 'POST',
                        data: {
                            id: id
                        },
                        success: function(response) {
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
            $('.check-status-btn').click(function() {
                var id = $(this).data('id');
                $.ajax({
                    url: './provider/scripts/check_status.php',
                    type: 'POST',
                    data: {
                        id: id
                    },
                    success: function(response) {
                        alert(response);
                        location.reload();
                    },
                    error: function() {
                        alert("An error occurred while checking the account status.");
                    }
                });
            });
        });
        $(document).ready(function() {
            $(".claim-btn").click(function() {
                var accountId = $(this).data("id");
                $.ajax({
                    url: "claim_account.php",
                    type: "POST",
                    data: {
                        id: accountId
                    },
                    success: function(response) {
                        alert(response);
                        location.reload();
                    },
                    error: function() {
                        alert("An error occurred while claiming the account.");
                    }
                });
            });
        });
    </script>
</body>

</html>