<?php
// bulk_regional_send.php

include('db.php'); // This file must initialize your $pdo connection

// Ensure account ID and user ID are provided via GET
if (!isset($_GET['ac_id']) || !isset($_GET['user_id'])) {
    echo "Account ID and User ID required.";
    exit;
}

$id = intval($_GET['ac_id']);
$user_id = intval($_GET['user_id']);

// Fetch AWS credentials for the provided account ID
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "Account not found.";
    exit;
}

$aws_key    = htmlspecialchars($account['aws_key']);
$aws_secret = htmlspecialchars($account['aws_secret']);
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
    .timer {
      font-weight: bold;
      margin-top: 10px;
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
  </style>
</head>
<body>
  <div class="container">
    <h1>Bulk Regional OTP Sending</h1>
    
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
    
    <!-- Table of numbers with OTP sent -->
    <h2>Numbers with OTP Sent</h2>
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
    
    <!-- Live Log -->
    <h2>Live Log</h2>
    <div id="log"></div>
  </div>
  
  <script>
    $(document).ready(function(){
      var userId = <?php echo $user_id; ?>;
      
      // List of regions to iterate automatically
      var regions = [
        "us-east-1", "us-east-2", "us-west-1", "us-west-2", "ap-south-1",
        "ap-northeast-3", "ap-southeast-1", "ap-southeast-2", "ap-northeast-1",
        "ca-central-1", "eu-central-1", "eu-west-1", "eu-west-2", "eu-west-3",
        "eu-north-1", "me-central-1", "sa-east-1", "af-south-1", "ap-southeast-3",
        "ap-southeast-4", "ca-west-1", "eu-south-1", "eu-south-2", "eu-central-2",
        "me-south-1", "il-central-1", "ap-south-2"
      ];
      var currentRegionIndex = 0;
      var regionSummary = {}; // Records OTP count for each region (successful sends)
      var regionErrors = {};  // Records error messages per region
      var totalSuccess = 0;
      
      // Reusable countdown function for delays.
      function startDelayTimer(delaySeconds, callback, region, prefixMessage) {
        var remaining = delaySeconds;
        $('#countdown').fadeIn().text(prefixMessage + " " + remaining + " seconds remaining... (" + region + ")");
        var interval = setInterval(function(){
          remaining--;
          if(remaining > 0){
            $('#countdown').text(prefixMessage + " " + remaining + " seconds remaining... (" + region + ")");
          } else {
            clearInterval(interval);
            $('#countdown').fadeOut();
            callback();
          }
        }, 1000);
      }
      
      $('#start-bulk-regional-otp').click(function(){
         $(this).prop('disabled', true);
         currentRegionIndex = 0;
         regionSummary = {};
         regionErrors = {};
         totalSuccess = 0;
         // Clear previous table rows, summary, and numbers text area
         $('#sent-numbers-table tbody').html('');
         $('#summary').html('');
         $('#numbers').val('');
         processNextRegion();
      });
      
      // Process each region one by one.
      function processNextRegion(){
         if(currentRegionIndex >= regions.length){
            displayFinalSummary();
            $('#process-status').removeClass('error').addClass('success')
              .text("All regions processed.").fadeIn();
            $('#start-bulk-regional-otp').prop('disabled', false);
            return;
         }
         
         var region = regions[currentRegionIndex];
         $('#process-status').removeClass('error').addClass('success')
            .text("Processing region: " + region).fadeIn();
         
         // Fetch allowed numbers for the current region.
         $.ajax({
            url: 'region_ajax_handler.php',
            type: 'POST',
            dataType: 'json',
            data: { 
              action: 'fetch_numbers', 
              region: region,
              user_id: userId  // Pass user_id here
            },
            success: function(response){
               if(response.status === 'success'){
                  var allowedNumbers = response.data;
                  var numbersDisplay = "Region: " + region + "\n";
                  if(allowedNumbers.length === 0){
                     numbersDisplay += "No allowed numbers found.\n";
                  } else {
                     allowedNumbers.forEach(function(item){
                        numbersDisplay += "ID: " + item.id + " | Phone: " + item.phone_number + " | ATM Left: " + item.atm_left + " | Created At: " + item.created_at + "\n";
                     });
                  }
                  // Append the numbers to the textarea.
                  $('#numbers').val($('#numbers').val() + "\n" + numbersDisplay);
                  
                  if(allowedNumbers.length === 0){
                     regionSummary[region] = 0;
                     currentRegionIndex++;
                     $('#process-status').text("No allowed numbers for region " + region + ". Moving to next region in 5 seconds...");
                     startDelayTimer(5, processNextRegion, region, "Delay:");
                  } else {
                      $('#numbers').val('Error: ' + response.message);
                  }
              },
              error: function(xhr, status, error) {
                  $('#numbers').val('AJAX error: ' + error);
              }
          });
      });
      
      $('#start-bulk-regional-otp').click(function(){
         var set_id = $('#set_id').val();
         if (!set_id) {
           alert("Please select a set.");
           return;
         }
         
         var task = tasks[index];
         $('#process-status').removeClass('error').addClass('success')
            .text("[" + region + "] Sending OTP to: " + task.phone).fadeIn();
         
         $.ajax({
            url: 'region_ajax_handler.php',
            type: 'POST',
            dataType: 'json',
            data: {
               action: 'send_otp_single',
               id: task.id,
               phone: task.phone,
               region: region,
               awsKey: awsKey,
               awsSecret: awsSecret,
               user_id: userId  // Pass user_id here as well
            },
            success: function(response){
               if(response.status === 'success'){
                  addSentNumberRow(region, task.id, task.phone, 'OTP Sent');
                  totalSuccess++;
                  regionSummary[region]++;
                  startDelayTimer(3, function(){ processOTPTasksForRegion(tasks, index + 1, region); }, region, "Waiting:");
               }
               else if(response.status === 'skip'){
                  // For errors like monthly spend limit reached, skip remaining tasks for this number.
                  addSentNumberRow(region, task.id, task.phone, 'Skipped: ' + response.message);
                  var currentId = task.id;
                  var newIndex = index + 1;
                  while(newIndex < tasks.length && tasks[newIndex].id === currentId){
                      newIndex++;
                  }
                  processOTPTasksForRegion(tasks, newIndex, region);
               }
               else if(response.status === 'error'){
                  // Check if error contains messages indicating we should skip the entire region.
                  if(response.message.indexOf("VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== -1 ||
                     response.message.indexOf("Access Denied") !== -1 ||
                     response.message.indexOf("Region Restricted") !== -1){
                     addSentNumberRow(region, task.id, task.phone, 'Failed: ' + response.message);
                     regionErrors[region] = "Error encountered (" + response.message + "). Skipping entire region.";
                     $('#process-status').removeClass('success').addClass('error')
                       .text("Region " + region + " encountered error (" + response.message + "). Moving to next region in 5 seconds...").fadeIn();
                     currentRegionIndex++;
                     startDelayTimer(3, processNextRegion, region, "Error delay:");
                     return;
                  } else {
                     // For other errors, log the failure and try the next OTP after a delay.
                     addSentNumberRow(region, task.id, task.phone, 'Failed: ' + response.message);
                     startDelayTimer(5, function(){ processOTPTasksForRegion(tasks, index + 1, region); }, region, "Waiting:");
                  }
               }
            },
            error: function(xhr, status, error){
               addSentNumberRow(region, task.id, task.phone, 'AJAX error: ' + error);
               startDelayTimer(5, function(){ processOTPTasksForRegion(tasks, index + 1, region); }, region, "Waiting:");
            }
         });
      }
      
      // Function to add a row to the "Numbers with OTP Sent" table.
      function addSentNumberRow(region, id, phone, status){
         var row = '<tr>' +
                   '<td>' + region + '</td>' +
                   '<td>' + id + '</td>' +
                   '<td>' + phone + '</td>' +
                   '<td>' + status + '</td>' +
                   '</tr>';
         $('#sent-numbers-table tbody').append(row);
      }
      
      // Function to display the final summary.
      function displayFinalSummary(){
         var summaryHtml = "<h3>Final Summary</h3>";
         summaryHtml += "<h4>Successful Regions:</h4><ul>";
         for(var r in regionSummary){
            if(!regionErrors[r]){
               summaryHtml += "<li>" + r + ": " + regionSummary[r] + " OTPs sent</li>";
            }
         }
         summaryHtml += "</ul>";
         
         summaryHtml += "<h4>Regions with Errors:</h4><ul>";
         for(var r in regionErrors){
             summaryHtml += "<li>" + r + ": " + regionErrors[r] + "</li>";
         }
         summaryHtml += "</ul>";
         
         summaryHtml += "<h4>Total OTPs sent: " + totalSuccess + "</h4>";
         $('#summary').html(summaryHtml);
      }
    });
  </script>
</body>
</html>
