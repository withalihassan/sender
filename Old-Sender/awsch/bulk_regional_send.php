<?php
// bulk_regional_send.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('../db.php'); // This file must initialize your $pdo connection

// Ensure account ID is provided via GET
if (!isset($_GET['ac_id'])) {
  echo "Account ID required.";
  exit;
}

$id = intval($_GET['ac_id']);

// Fetch AWS credentials for the provided account ID from child_accounts table
$stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
  echo "Account not found.";
  exit;
}

$accountId = $id; // using provided account id

// Set the timezone to Asia/Karachi
date_default_timezone_set('Asia/Karachi');
$currentTimestamp = date('Y-m-d H:i:s');

// Retrieve AWS keys from child_accounts
$aws_key    = $account['aws_access_key'];
$aws_secret = $account['aws_secret_key'];

// STREAMING MODE: If stream=1 is present, run the SSE loop.
if (isset($_GET['stream'])) {
  if (!isset($_GET['set_id']) || intval($_GET['set_id']) <= 0) {
    echo "No set selected.";
    exit;
  }
  $set_id = intval($_GET['set_id']);

  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  while (ob_get_level()) {
    ob_end_flush();
  }
  set_time_limit(0);
  ignore_user_abort(true);

  function sendSSE($type, $message)
  {
    echo "data:" . $type . "|" . str_replace("\n", "\\n", $message) . "\n\n";
    flush();
  }

  sendSSE("STATUS", "Starting Bulk Regional OTP Process for Set ID: " . $set_id);

  $regions = array(
    "us-east-1",
    "us-east-2",
    "us-west-1",
    "us-west-2",
    "ap-south-1",
    "ap-northeast-3",
    "ap-southeast-1",
    "ap-southeast-2",
    "ap-northeast-1",
    "ca-central-1",
    "eu-central-1",
    "eu-west-1",
    "eu-west-2",
    "eu-west-3",
    "eu-north-1",
    "me-central-1",
    "sa-east-1",
    "af-south-1",
    "ap-southeast-3",
    "ap-southeast-4",
    "ca-west-1",
    "eu-south-1",
    "eu-south-2",
    "eu-central-2",
    "me-south-1",
    "il-central-1",
    "ap-south-2"
  );
  $totalRegions = count($regions);
  $totalSuccess = 0;
  $usedRegions = 0;

  $internal_call = true;
  require_once('region_ajax_handler.php');

  foreach ($regions as $region) {
    $usedRegions++;
    sendSSE("STATUS", "Moving to region: " . $region);
    sendSSE("COUNTERS", "Total OTP sent: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));

    // Fetch phone numbers based solely on the set_id
    $numbersResult = fetch_numbers($region, $pdo, $set_id);
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

    // Build OTP tasks.
    $otpTasks = array();
    $first = $allowedNumbers[0];
    for ($i = 0; $i < 4; $i++) {
      $otpTasks[] = array('id' => $first['id'], 'phone' => $first['phone_number']);
    }
    for ($i = 1; $i < min(count($allowedNumbers), 10); $i++) {
      $otpTasks[] = array('id' => $allowedNumbers[$i]['id'], 'phone' => $allowedNumbers[$i]['phone_number']);
    }

    $otpSentInThisRegion = false;
    $verifDestError = false;

    foreach ($otpTasks as $task) {
      sendSSE("STATUS", "[$region] Sending OTP...");
      $sns = initSNS($aws_key, $aws_secret, $region);
      if (is_array($sns) && isset($sns['error'])) {
        sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|OTP Failed: " . $sns['error']);
        continue;
      }
      $result = send_otp_single($task['id'], $task['phone'], $region, $aws_key, $aws_secret, $pdo, $sns);
      if ($result['status'] === 'success') {
        sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|OTP Sent");
        $totalSuccess++;
        $otpSentInThisRegion = true;
        sendSSE("COUNTERS", "Total OTP sent: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));
        sleep(3);
      } else if ($result['status'] === 'skip') {
        sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|OTP Skipped: " . $result['message']);
      } else if ($result['status'] === 'error') {
        sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|OTP Failed: " . $result['message']);
        if (strpos($result['message'], "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
          $verifDestError = true;
          sendSSE("STATUS", "[$region] VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error encountered. Skipping region.");
          break;
        } else if (
          strpos($result['message'], "Access Denied") !== false ||
          strpos($result['message'], "Region Restricted") !== false
        ) {
          sendSSE("STATUS", "[$region] Critical error (" . $result['message'] . "). Skipping region.");
          break;
        } else {
          sleep(5);
        }
      }
    }
    if ($verifDestError) {
      sendSSE("STATUS", "Region $region encountered an error. Waiting 5 seconds...");
      sleep(5);
    } else if ($otpSentInThisRegion) {
      sendSSE("STATUS", "Completed OTP sending for region $region. Waiting 60 seconds...");
      sleep(60);
    } else {
      sendSSE("STATUS", "Completed OTP sending for region $region. Waiting 5 seconds...");
      sleep(5);
    }
  }

  $summary = "Final Summary:<br>Total OTP sent: $totalSuccess<br>Regions processed: $usedRegions<br>Remaining regions: " . ($totalRegions - $usedRegions);
  sendSSE("SUMMARY", $summary);
  sendSSE("STATUS", "Process Completed.");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?php echo $id;  ?> | Bulk Regional OTP Sending</title>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f7f7f7; }
    .container { max-width: 800px; margin: auto; background: #fff; padding: 20px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); border-radius: 5px; }
    h1, h2 { text-align: center; color: #333; }
    label { font-weight: bold; margin-top: 10px; display: block; }
    input, textarea, select, button { width: 100%; padding: 10px; margin: 10px 0; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; }
    button { background: #007bff; color: #fff; border: none; cursor: pointer; font-size: 16px; }
    button:disabled { background: #6c757d; cursor: not-allowed; }
    .message { padding: 10px; border-radius: 5px; margin: 10px 0; display: none; }
    .success { background: #d4edda; color: #155724; }
    .error { background: #f8d7da; color: #721c24; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    table, th, td { border: 1px solid #ccc; }
    th, td { padding: 5px; text-align: center; }
    th { background: #f4f4f4; }
    #counters { background: #eee; color: #333; padding: 5px 10px; margin: 10px 0; font-weight: bold; text-align: center; font-size: 14px; border: 1px solid #ccc; border-radius: 3px; display: inline-block; width: auto; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Bulk Regional OTP Sending</h1>
    <?php
    // Fetch available sets from bulk_sets.
    $stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets ORDER BY set_name ASC");
    $sets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <form id="bulk-regional-otp-form">
      <label for="set_id">Select Set:</label>
      <select id="set_id" name="set_id" required>
        <option value="">-- Select a Set --</option>
        <?php foreach ($sets as $set): ?>
          <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
        <?php endforeach; ?>
      </select>

      <!-- AWS Credentials -->
      <label for="awsKey">AWS Key:</label>
      <input type="text" id="awsKey" name="awsKey" value="<?php echo $aws_key; ?>" disabled>

      <label for="awsSecret">AWS Secret:</label>
      <input type="text" id="awsSecret" name="awsSecret" value="<?php echo $aws_secret; ?>" disabled>

      <button type="button" id="start-bulk-regional-otp">Start Bulk OTP Process for Selected Set</button>
    </form>

    <!-- Display area for allowed numbers (read-only) -->
    <label for="numbers">Allowed Phone Numbers (from database):</label>
    <textarea id="numbers" name="numbers" rows="10" readonly></textarea>

    <!-- Status messages -->
    <div id="process-status" class="message"></div>

    <!-- Live Counters -->
    <h2>Live Counters</h2>
    <div id="counters"></div>

    <!-- Table of OTP events: ID, Phone Number, Region, Status -->
    <h2>OTP Events</h2>
    <table id="sent-numbers-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Phone Number</th>
          <th>Region</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <!-- Final Summary -->
    <h2>Final Summary</h2>
    <div id="summary"></div>
  </div>

  <script>
    $(document).ready(function() {
      var acId = <?php echo $id; ?>;

      // When a set is selected, fetch allowed numbers for that set via AJAX.
      $('#set_id').change(function() {
        var set_id = $(this).val();
        if (!set_id) {
          $('#numbers').val('');
          return;
        }
        $.ajax({
          url: 'region_ajax_handler.php',
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'fetch_numbers',
            region: 'dummy',
            set_id: set_id
          },
          success: function(response) {
            if (response.status === 'success') {
              var displayText = "";
              response.data.forEach(function(item) {
                displayText += "ID: " + item.id + " | Phone: " + item.phone_number + " | ATM Left: " + item.atm_left + " | Date: " + item.formatted_date + "\n";
              });
              $('#numbers').val(displayText);
            } else {
              $('#numbers').val('Error: ' + response.message);
            }
          },
          error: function(xhr, status, error) {
            $('#numbers').val('AJAX error: ' + error);
          }
        });
      });

      $('#start-bulk-regional-otp').click(function() {
        var set_id = $('#set_id').val();
        if (!set_id) {
          alert("Please select a set.");
          return;
        }
        $(this).prop('disabled', true);
        $('#process-status').removeClass('error').removeClass('success').text('');
        $('#numbers').val('');
        $('#sent-numbers-table tbody').html('');
        $('#summary').html('');
        $('#counters').html('');

        // Start SSE connection with the selected set_id.
        var evtSource = new EventSource("bulk_regional_send.php?ac_id=" + acId + "&set_id=" + set_id + "&stream=1");
        evtSource.onmessage = function(e) {
          var data = e.data;
          var parts = data.split("|");
          var type = parts[0];
          if (type === "ROW") {
            // Expected format: ROW|ID|Phone|Region|Status
            var id = parts[1];
            var phone = parts[2];
            var region = parts[3];
            var status = parts.slice(4).join("|");
            var row = '<tr><td>' + id + '</td><td>' + phone + '</td><td>' + region + '</td><td>' + status + '</td></tr>';
            $('#sent-numbers-table tbody').append(row);
          } else {
            var content = parts.slice(1).join("|").replace(/\\n/g, "<br>");
            if (type === "STATUS") {
              $('#process-status').text(content).show();
            } else if (type === "COUNTERS") {
              $('#counters').html(content);
            } else if (type === "SUMMARY") {
              $('#summary').html(content);
            }
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
