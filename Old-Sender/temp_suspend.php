<?php
// temp_suspend.php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

include('db.php');

if(isset($_POST['id'])) {
    $id = $_POST['id'];
    // $currentDateTime = date("Y-m-d H:i:s");
    // Update the account's status to "suspended", set suspend_mode to "manual",
    // and update suspended_date to the current time.
    $stmt = $pdo->prepare("UPDATE accounts SET status = 'suspended', suspend_mode = 'manual', suspended_date = NOW() WHERE id = ?");
    if($stmt->execute([$id])) {
        echo "Account temporarily suspended successfully.";
    } else {
        echo "Failed to suspend account.";
    }
} else {
    echo "No account ID provided.";
}
?>
