<?php
// region_ajax_handler.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Include the AWS PHP SDK autoloader
require_once __DIR__ . '/aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

// Retrieve AWS credentials from POST (or use defaults)
$awsKey = isset($_POST['awsKey']) && !empty($_POST['awsKey']) ? $_POST['awsKey'] : 'DEFAULT_AWS_KEY';
$awsSecret = isset($_POST['awsSecret']) && !empty($_POST['awsSecret']) ? $_POST['awsSecret'] : 'DEFAULT_AWS_SECRET';

// Determine AWS region (default: ap-south-1, overridden by POST)
$awsRegion = 'ap-south-1';
if (!empty($_POST['region'])) {
    $awsRegion = trim($_POST['region']);
}

// Initialize the SNS client
try {
    $sns = new SnsClient([
        'version'     => 'latest',
        'region'      => $awsRegion,
        'credentials' => [
            'key'    => $awsKey,
            'secret' => $awsSecret,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error initializing SNS client: ' . $e->getMessage()
    ]);
    exit;
}

// Connect to database
include('db.php'); // Ensure this file initializes $pdo

$action = isset($_POST['action']) ? $_POST['action'] : '';

// Retrieve user_id from POST (passed from bulk_regional_send.php)
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

if ($action === 'fetch_numbers') {
    // Fetch phone numbers with atm_left > 0 for the given user
    $region = isset($_POST['region']) ? trim($_POST['region']) : '';
    if (empty($region)) {
        echo json_encode(['status' => 'error', 'message' => 'Region is required.']);
        exit;
    }
    
    // Adjusted query to filter by user_id (by_user column)
    $stmt = $pdo->prepare("SELECT id, phone_number, atm_left, created_at FROM allowed_numbers WHERE status = 'fresh' AND atm_left > 0 AND by_user = ? ORDER BY RAND()");
    $stmt->execute([$user_id]);
    $numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'region' => $region,
        'data'   => $numbers
    ]);
    exit;
}

elseif ($action === 'send_otp_single') {
    // Send OTP and update its database record
    $id    = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $region = isset($_POST['region']) ? trim($_POST['region']) : $awsRegion;

    if (!$id || empty($phone)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Invalid phone number or ID.',
            'region'  => $region
        ]);
        exit;
    }

    // Fetch current atm_left for the number (ensuring it belongs to the specified user)
    $stmt = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ? AND by_user = ?");
    $stmt->execute([$id, $user_id]);
    $numberData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$numberData) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Number not found in database.',
            'region'  => $region
        ]);
        exit;
    }

    $current_atm = intval($numberData['atm_left']);
    if ($current_atm <= 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'No remaining OTP attempts for this number.',
            'region'  => $region
        ]);
        exit;
    }

    // Send OTP via AWS SNS
    try {
        $result = $sns->createSMSSandboxPhoneNumber([
            'PhoneNumber' => $phone,
        ]);
    } catch (AwsException $e) {
        $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
        
        // Handle monthly spend limit error.
        if (strpos($errorMsg, "MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT") !== false) {
            echo json_encode([
                'status'  => 'skip',
                'message' => "Monthly spend limit reached. Skipping this number.",
                'region'  => $region
            ]);
            exit;
        }
        
        // Handle verified destination numbers error.
        if (strpos($errorMsg, "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Try another region.",
                'region'  => $region
            ]);
            exit;
        }
        
        // Handle Access Denied error by showing a simplified message.
        if (strpos($errorMsg, "Access Denied") !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => "Region Restricted moving to next",
                'region'  => $region
            ]);
            exit;
        }
        
        echo json_encode([
            'status'  => 'error',
            'message' => "Error sending OTP: " . $errorMsg,
            'region'  => $region
        ]);
        exit;
    }

    // Decrease atm_left count
    try {
        $new_atm = $current_atm - 1;
        $new_status = ($new_atm == 0) ? 'used' : 'fresh';
        $last_used = date("Y-m-d H:i:s");

        $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = ?, status = ? WHERE id = ? AND by_user = ?");
        $updateStmt->execute([$new_atm, $last_used, $new_status, $id, $user_id]);

    } catch (PDOException $e) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database update error: ' . $e->getMessage(),
            'region'  => $region
        ]);
        exit;
    }

    echo json_encode([
        'status'  => 'success',
        'message' => "OTP sent to $phone successfully.",
        'region'  => $region
    ]);
    exit;
}

else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid action.',
        'region'  => $awsRegion
    ]);
    exit;
}
?>
