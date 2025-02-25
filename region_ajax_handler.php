<?php
// region_ajax_handler.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// If not an internal call, set JSON header.
if (empty($internal_call)) {
    header('Content-Type: application/json');
}

// Include the AWS PHP SDK autoloader.
require_once __DIR__ . '/aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

// Function to initialize the SNS client.
function initSNS($awsKey, $awsSecret, $awsRegion) {
    try {
        $sns = new SnsClient([
            'version'     => 'latest',
            'region'      => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
        return $sns;
    } catch (Exception $e) {
        return ['error' => 'Error initializing SNS client: ' . $e->getMessage()];
    }
}

// Function to fetch allowed phone numbers.
// If $set_id is provided, numbers are filtered by that set.
function fetch_numbers($region, $user_id, $pdo, $set_id = null) {
    if (empty($region)) {
        return ['error' => 'Region is required.'];
    }
    $query = "SELECT id, phone_number, atm_left, created_at FROM allowed_numbers WHERE status = 'fresh' AND atm_left > 0 AND by_user = ?";
    $params = [$user_id];
    if (!empty($set_id)) {
        $query .= " AND set_id = ?";
        $params[] = $set_id;
    }
    $query .= " ORDER BY RAND() LIMIT 50";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['success' => true, 'region' => $region, 'data' => $numbers];
}

// Function to send OTP for a single number.
function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $user_id, $pdo, $sns) {
    if (!$id || empty($phone)) {
        return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
    }
    $stmt = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ? AND by_user = ?");
    $stmt->execute([$id, $user_id]);
    $numberData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$numberData) {
        return ['status' => 'error', 'message' => 'Number not found in database.', 'region' => $region];
    }
    $current_atm = intval($numberData['atm_left']);
    if ($current_atm <= 0) {
        return ['status' => 'error', 'message' => 'No remaining OTP attempts for this number.', 'region' => $region];
    }
    try {
        $result = $sns->createSMSSandboxPhoneNumber([
            'PhoneNumber' => $phone,
        ]);
    } catch (AwsException $e) {
        $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
        if (strpos($errorMsg, "MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT") !== false) {
            return ['status' => 'skip', 'message' => "Monthly spend limit reached. Skipping this number.", 'region' => $region];
        }
        if (strpos($errorMsg, "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
            return ['status' => 'error', 'message' => "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Try another region.", 'region' => $region];
        }
        if (strpos($errorMsg, "Access Denied") !== false) {
            return ['status' => 'error', 'message' => "Region Restricted moving to next", 'region' => $region];
        }
        return ['status' => 'error', 'message' => "Error sending OTP: " . $errorMsg, 'region' => $region];
    }
    try {
        $new_atm = $current_atm - 1;
        $new_status = ($new_atm == 0) ? 'used' : 'fresh';
        $last_used = date("Y-m-d H:i:s");
        $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = ?, status = ? WHERE id = ? AND by_user = ?");
        $updateStmt->execute([$new_atm, $last_used, $new_status, $id, $user_id]);
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Database update error: ' . $e->getMessage(), 'region' => $region];
    }
    return ['status' => 'success', 'message' => "OTP sent to $phone successfully.", 'region' => $region];
}

if (empty($internal_call)) {
    $awsKey    = isset($_POST['awsKey']) && !empty($_POST['awsKey']) ? $_POST['awsKey'] : 'DEFAULT_AWS_KEY';
    $awsSecret = isset($_POST['awsSecret']) && !empty($_POST['awsSecret']) ? $_POST['awsSecret'] : 'DEFAULT_AWS_SECRET';
    $awsRegion = 'ap-south-1';
    if (!empty($_POST['region'])) {
        $awsRegion = trim($_POST['region']);
    }
    $action  = isset($_POST['action']) ? $_POST['action'] : '';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    $sns = initSNS($awsKey, $awsSecret, $awsRegion);
    if (is_array($sns) && isset($sns['error'])) {
        echo json_encode(['status' => 'error', 'message' => $sns['error']]);
        exit;
    }
    
    if ($action === 'fetch_numbers') {
        $region = isset($_POST['region']) ? trim($_POST['region']) : '';
        $set_id = isset($_POST['set_id']) ? trim($_POST['set_id']) : '';
        $result = fetch_numbers($region, $user_id, $pdo, $set_id);
        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $result['error']]);
        } else {
            echo json_encode(array_merge(['status' => 'success'], $result));
        }
        exit;
    } elseif ($action === 'send_otp_single') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $region = isset($_POST['region']) ? trim($_POST['region']) : $awsRegion;
        $result = send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $user_id, $pdo, $sns);
        echo json_encode($result);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.', 'region' => $awsRegion]);
        exit;
    }
}
?>
