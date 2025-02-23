<?php
// bulk_regional_send.php

include('db.php'); // This file must initialize your $pdo connection

// Ensure an account ID is provided via GET
if (!isset($_GET['ac_id'])) {
    echo "No account ID provided.";
    exit;
}

$id = intval($_GET['ac_id']);
$user_id = intval($_GET['user_id']);

// Fetch the AWS key and secret for the provided account ID
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "Account not found.";
    exit;
}

$aws_key    = htmlspecialchars($account['aws_key']);
$aws_secret = htmlspecialchars($account['aws_secret']);

// If this request is for SSE streaming, process the OTP tasks server-side and stream updates.
if (isset($_GET['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    while (ob_get_level()) { ob_end_flush(); }
    set_time_limit(0);
    ignore_user_abort(true);

    // Helper function to send an SSE event.
    function sendSSE($type, $message) {
        // Replace newlines with \n for proper SSE formatting.
        echo "data:" . $type . "|" . str_replace("\n", "\\n", $message) . "\n\n";
        flush();
    }

    sendSSE("STATUS", "Starting Bulk Regional OTP Process");

    // List of regions to process.
    $regions = array(
        "us-east-1", "us-east-2", "us-west-1", "us-west-2", "ap-south-1",
        "ap-northeast-3", "ap-southeast-1", "ap-southeast-2", "ap-northeast-1",
        "ca-central-1", "eu-central-1", "eu-west-1", "eu-west-2", "eu-west-3",
        "eu-north-1", "me-central-1", "sa-east-1", "af-south-1", "ap-southeast-3",
        "ap-southeast-4", "ca-west-1", "eu-south-1", "eu-south-2", "eu-central-2",
        "me-south-1", "il-central-1", "ap-south-2"
    );
    $totalRegions = count($regions);
    $totalSuccess = 0;
    $usedRegions = 0; // Regions that have been processed

    // Include the AJAX handler file in "internal" mode so its functions are available.
    $internal_call = true;
    require_once('region_ajax_handler.php');

    // Process each region in turn.
    foreach ($regions as $region) {
        $usedRegions++;  // Mark this region as processed
        sendSSE("STATUS", "Moving to region: " . $region);
        sendSSE("COUNTERS", "Total OTP sended: $totalSuccess; Currently sending in region: $region; Total regions used: $usedRegions; Remaining regions: " . ($totalRegions - $usedRegions));

        // Fetch allowed numbers for the region.
        $numbersResult = fetch_numbers($region, $user_id, $pdo);
        if (isset($numbersResult['error'])) {
            sendSSE("STATUS", "Error fetching numbers for region " . $region . ": " . $numbersResult['error']);
            sleep(5);
            continue;
        }
        $allowedNumbers = $numbersResult['data'];
        if (empty($allowedNumbers)) {
            sendSSE("STATUS", "No allowed numbers found in region: " . $region);
            sleep(5);
            continue;
        }

        // Build OTP tasks:
        // • First allowed number gets 4 OTPs.
        // • Next up to 9 allowed numbers (if available) get 1 OTP each.
        $otpTasks = array();
        $first = $allowedNumbers[0];
        for ($i = 0; $i < 4; $i++) {
            $otpTasks[] = array('id' => $first['id'], 'phone' => $first['phone_number']);
        }
        for ($i = 1; $i < min(count($allowedNumbers), 10); $i++){
            $otpTasks[] = array('id' => $allowedNumbers[$i]['id'], 'phone' => $allowedNumbers[$i]['phone_number']);
        }

        // Initialize flags for this region.
        $otpSentInThisRegion = false;
        $verifDestError = false;

        // Process each OTP task for this region.
        foreach ($otpTasks as $task) {
            sendSSE("STATUS", "[$region] Sending OTP...");
            // Initialize SNS client for this region.
            $sns = initSNS($aws_key, $aws_secret, $region);
            if (is_array($sns) && isset($sns['error'])) {
                sendSSE("ROW", "$region|OTP Failed: " . $sns['error']);
                continue;
            }
            $result = send_otp_single($task['id'], $task['phone'], $region, $aws_key, $aws_secret, $user_id, $pdo, $sns);
            if ($result['status'] === 'success') {
                sendSSE("ROW", "$region|OTP Sent");
                $totalSuccess++;
                $otpSentInThisRegion = true;
                sendSSE("COUNTERS", "Total OTP sended: $totalSuccess; Currently sending in region: $region; Total regions used: $usedRegions; Remaining regions: " . ($totalRegions - $usedRegions));
                sleep(3);
            } else if ($result['status'] === 'skip') {
                sendSSE("ROW", "$region|OTP Skipped: " . $result['message']);
            } else if ($result['status'] === 'error') {
                sendSSE("ROW", "$region|OTP Failed: " . $result['message']);
                if (strpos($result['message'], "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
                    $verifDestError = true;
                    sendSSE("STATUS", "[$region] VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error encountered. Skipping region.");
                    break;
                } else if (strpos($result['message'], "Access Denied") !== false ||
                           strpos($result['message'], "Region Restricted") !== false) {
                    sendSSE("STATUS", "[$region] Critical error encountered (" . $result['message'] . "). Skipping region.");
                    break;
                } else {
                    sleep(5);
                }
            }
        }
        // Determine wait time for next region.
        if ($verifDestError) {
            sendSSE("STATUS", "Region $region encountered VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Waiting 5 seconds before next region...");
            sleep(5);
        } else if ($otpSentInThisRegion) {
            sendSSE("STATUS", "Completed OTP sending for region $region. Waiting 60 seconds before next region...");
            sleep(60);
        } else {
            sendSSE("STATUS", "Completed OTP sending for region $region. Waiting 5 seconds before next region...");
            sleep(5);
        }
    }

    // Build and send the final summary.
    $summary = "Final Summary:<br>Total OTP sended: $totalSuccess<br>Total regions used: $usedRegions<br>Remaining regions: " . ($totalRegions - $usedRegions);
    sendSSE("SUMMARY", $summary);
    sendSSE("STATUS", "Process Completed.");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Bulk Regional OTP Sending</title>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    /* Basic styling */
    body {
      font-family: Arial, sans-serif;
      margin: 20px;
      background: #f7f7f7;
    }
    .container {
      max-width: 800px;
      margin: auto;
      background: #fff;
      padding: 20px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      border-radius: 5px;
    }
    h1, h2 {
      text-align: center;
      color: #333;
    }
    label {
      font-weight: bold;
      margin-top: 10px;
      display: block;
    }
    input, textarea, button {
      width: 100%;
      padding: 10px;
      margin: 10px 0;
      border-radius: 4px;
      border: 1px solid #ccc;
      box-sizing: border-box;
    }
    button {
      background: #007bff;
      color: #fff;
      border: none;
      cursor: pointer;
      font-size: 16px;
    }
    button:disabled {
      background: #6c757d;
      cursor: not-allowed;
    }
    .message {
      padding: 10px;
      border-radius: 5px;
      margin: 10px 0;
      display: none;
    }
    .success {
      background: #d4edda;
      color: #155724;
    }
    .error {
      background: #f8d7da;
      color: #721c24;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }
    table, th, td {
      border: 1px solid #ccc;
    }
    th, td {
      padding: 5px;
      text-align: center;
    }
    th {
      background: #f4f4f4;
    }
    #log {
      background: #000;
      color: #0f0;
      padding: 10px;
      height: 200px;
      overflow-y: scroll;
      font-family: monospace;
      font-size: 13px;
    }
    /* Minimal live counters styling */
    #counters {
      background: #eee;
      color: #333;
      padding: 5px 10px;
      margin: 10px 0;
      font-weight: bold;
      text-align: center;
      font-size: 14px;
      border: 1px solid #ccc;
      border-radius: 3px;
      display: inline-block;
      width: auto;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>Bulk Regional OTP Sending</h1>
    
    <form id="bulk-regional-otp-form">
      <!-- AWS Credentials (pre-filled and disabled) -->
      <label for="awsKey">AWS Key:</label>
      <input type="text" id="awsKey" name="awsKey" value="<?php echo $aws_key; ?>" disabled>
      
      <label for="awsSecret">AWS Secret:</label>
      <input type="text" id="awsSecret" name="awsSecret" value="<?php echo $aws_secret; ?>" disabled>
      
      <button type="button" id="start-bulk-regional-otp">Start Bulk OTP Process for All Regions</button>
    </form>
    
    <!-- Display area for allowed numbers (read-only) -->
    <label for="numbers">Allowed Phone Numbers (from database):</label>
    <textarea id="numbers" name="numbers" rows="10" readonly></textarea>
    
    <!-- Status messages -->
    <div id="process-status" class="message"></div>
    
    <!-- Live Counters -->
    <h2>Live Counters</h2>
    <div id="counters"></div>
    
    <!-- Table of OTP events -->
    <h2>OTP Events</h2>
    <table id="sent-numbers-table">
      <thead>
        <tr>
          <th>Region</th>
          <th>Event</th>
        </tr>
      </thead>
      <tbody>
        <!-- Rows will be added dynamically -->
      </tbody>
    </table>
    
    <!-- Final Summary -->
    <h2>Final Summary</h2>
    <div id="summary"></div>
    
    <!-- Live Log -->
    <h2>Live Log</h2>
    <div id="log"></div>
  </div>
  
  <script>
    $(document).ready(function(){
      var userId = <?php echo $user_id; ?>;
      var acId = <?php echo $id; ?>;
      
      $('#start-bulk-regional-otp').click(function(){
         $(this).prop('disabled', true);
         $('#process-status').removeClass('error').removeClass('success').text('');
         $('#numbers').val('');
         $('#sent-numbers-table tbody').html('');
         $('#summary').html('');
         $('#log').html('');
         $('#counters').html('');
         
         // Start SSE connection.
         var evtSource = new EventSource("bulk_regional_send.php?ac_id=" + acId + "&user_id=" + userId + "&stream=1");
         evtSource.onmessage = function(e) {
             // Our messages use the format: TYPE|content
             var data = e.data;
             var parts = data.split("|");
             var type = parts[0];
             var content = parts.slice(1).join("|").replace(/\\n/g, "<br>");
             
             if (type === "STATUS") {
                 $('#process-status').text(content).show();
                 $('#log').append("STATUS: " + content + "<br>");
             } else if (type === "COUNTERS") {
                 $('#counters').html(content);
                 $('#log').append("COUNTERS: " + content + "<br>");
             } else if (type === "ROW") {
                 // Format: "ROW|region|message"
                 var region = parts[1];
                 var message = parts.slice(2).join("|");
                 var row = '<tr><td>' + region + '</td><td>' + message + '</td></tr>';
                 $('#sent-numbers-table tbody').append(row);
                 $('#log').append("[" + region + "] " + message + "<br>");
             } else if (type === "SUMMARY") {
                 $('#summary').html(content);
                 $('#log').append("SUMMARY: " + content + "<br>");
             }
         };
         evtSource.onerror = function() {
             $('#process-status').text("An error occurred with the SSE connection.").addClass('error').show();
             evtSource.close();
         };
      });
    });
  </script>
</body>
</html>
