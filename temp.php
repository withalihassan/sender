// brs.php (modified section)
// ... [Previous code remains unchanged until regions loop]

// List of regions that require enable checks
$checkEnableRegions = [
    "me-central-1",
    "ap-southeast-3",
    "ap-southeast-4",
    "eu-south-2",
    "eu-central-2",
    "ap-south-2"
];

foreach ($regions as $region) {
    $usedRegions++;
    sendSSE("STATUS", "Moving to region: " . $region);
    sendSSE("COUNTERS", "Total Patch Done: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));

    // Region enable check and wait logic - NEW SECTION
    if (in_array($region, $checkEnableRegions)) {
        $enabled = false;
        $retryCount = 0;
        
        while (!$enabled) {
            try {
                // Create region-specific EC2 client
                $ec2Client = new Ec2Client([
                    'version' => 'latest',
                    'region' => $region,
                    'credentials' => [
                        'key'    => $aws_key,
                        'secret' => $aws_secret,
                    ],
                ]);

                // Test region availability
                $ec2Client->describeInstanceTypeOfferings([
                    'LocationType' => 'region'
                ]);
                
                $enabled = true;
                sendSSE("STATUS", "✅ Region $region enabled verification passed");
            } catch (AwsException $e) {
                $errorCode = $e->getAwsErrorCode();
                if ($errorCode === 'OptInRequired') {
                    sendSSE("STATUS", "⏳ Region $region requires enablement. Waiting 30 seconds... (Retry #$retryCount)");
                    $retryCount++;
                    sleep(30);
                } else {
                    sendSSE("STATUS", "⚠️ Error checking region $region: " . $e->getAwsErrorMessage());
                    sleep(30);
                }
            }

            // Check stop condition
            if (file_exists($stopFile)) {
                sendSSE("STATUS", "Process stopped by user.");
                unlink($stopFile);
                exit;
            }
        }
    }

    // Rest of the existing region processing remains the same
    // ...[Continue with original number processing logic]