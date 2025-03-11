<?php
date_default_timezone_set('Asia/Karachi');
include 'db.php';
include './session.php';

if (!isset($_POST['id']) || !isset($_POST['claim_type'])) {
    die('Invalid request.');
}

$id = $_POST['id'];
$claim_type = strtolower(trim($_POST['claim_type']));

// Validate claim type
if ($claim_type !== 'full' && $claim_type !== 'half') {
    die('Invalid claim type.');
}

// Fetch the current account state
$stmt = $pdo->prepare("SELECT ac_state FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    die('Account does not exist.');
}

if ($account['ac_state'] === 'orphan') {
    // Claim the account: update state to claimed, set claimed_by and worth_type
    $stmt = $pdo->prepare("UPDATE accounts SET ac_state = 'claimed', claimed_by = ?, worth_type = ? WHERE id = ?");
    if ($stmt->execute([$session_id, $claim_type, $id])) {
        echo "Account claimed successfully as " . htmlspecialchars($claim_type) . ".";
    } else {
        echo "Failed to claim account.";
    }
} else if ($account['ac_state'] === 'claimed') {
    // Get the current datetime
    $currentDateTime = date("Y-m-d H:i:s");
    
    // Start a transaction for data integrity
    $pdo->beginTransaction();
    
    try {
        // Step 1: Fetch the original account row
        $stmtSelect = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
        $stmtSelect->execute([$id]);
        $original = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        
        if (!$original) {
            throw new Exception('Account not found.');
        }
        
        // Step 2: Update the original row: mark as suspended and set suspended_date to current datetime
        $stmtUpdate = $pdo->prepare("UPDATE accounts SET status = 'suspended', suspended_date = ? WHERE id = ?");
        if (!$stmtUpdate->execute([$currentDateTime, $id])) {
            throw new Exception('Failed to mark the account as suspended.');
        }
        
        // Step 3: Duplicate the row with modifications:
        // Remove primary key so a new one is generated
        unset($original['id']);
        
        // Set new values for the duplicated row:
        // New row should have worth_type as 'half', status as 'active', ac_state as 'claimed', and ac_score as 0.
        // Also, record the conversion datetime.
        $original['worth_type'] = 'half';
        $original['status'] = 'active';
        $original['ac_state'] = 'claimed';
        $original['ac_score'] = 0;
        $original['added_date'] = $currentDateTime;
        $original['converted_to_half_at'] = $currentDateTime;
        
        // Dynamically build the INSERT query using the keys from $original.
        $fields = array_keys($original);
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $fieldList = implode(',', $fields);
        
        $stmtInsert = $pdo->prepare("INSERT INTO accounts ($fieldList) VALUES ($placeholders)");
        $values = array_values($original);
        
        if (!$stmtInsert->execute($values)) {
            throw new Exception('Failed to insert the new duplicated account.');
        }
        
        // Commit the transaction if both operations succeeded
        $pdo->commit();
        echo "Account duplicated successfully: previous row marked as suspended and new row created as claimed half account.";
    } catch (Exception $e) {
        // Rollback on any error
        $pdo->rollBack();
        echo "Error: " . $e->getMessage();
    }
} else {
    die('Account cannot be claimed.');
}
