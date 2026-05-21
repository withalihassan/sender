<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Tencent Cloud CAM Sub‐User Manager (Create + Resend SMS + Inline Feedback).
 *
 * 1. “Phone Number” must be entered as +<CountryCode><SubscriberNumber>,
 *    e.g. +8613700000000 or +14155552671. We parse out CountryCode (1–3 digits)
 *    and SubscriberNumber (4–14 digits).
 *
 * 2. When you click “Resend SMS” for a given UIN, the page reloads and shows
 *    an inline <div> under that row indicating success or the specific error.
 *
 * 3. Top banners (“alert‐success” / “alert‐danger”) are reserved for sub‐user
 *    creation events. Resend‐SMS feedback appears only inline.
 *
 * 4. Make sure your CAM credentials allow:
 *    - cam:AddUser
 *    - cam:ListUsers
 *    - cam:SendUserMobileCode
 *
 * 5. Ensure the newly created sub‐user has “Receive Messages” enabled in CAM console.
 */

require_once __DIR__ . '/sdk/TCloudAutoLoader.php';  // ← Adjust this path if needed

use TencentCloud\Common\Credential;
use TencentCloud\Cam\V20190116\CamClient;
use TencentCloud\Cam\V20190116\Models\AddUserRequest;
use TencentCloud\Cam\V20190116\Models\ListUsersRequest;
use TencentCloud\Cam\V20190116\Models\SendUserMobileCodeRequest;
use TencentCloud\Common\Exception\TencentCloudSDKException;

// ──────────────────────────────────────────────────────────────────────────────
//  CONFIGURATION (replace with your actual SecretId/SecretKey):
// ──────────────────────────────────────────────────────────────────────────────
$secretId  = 'IKIDsdAXigRzLTP9i5FE0aw64pOksoYLvHRP';
$secretKey = 'oNiqwpUyGLCj4B5nIbhZDrKaeWKvxnRs';
$region    = 'ap-guangzhou';  // CAM is global; “ap-guangzhou” works fine.

$cred   = new Credential($secretId, $secretKey);
$client = new CamClient($cred, $region);

// ──────────────────────────────────────────────────────────────────────────────
//  STATE / FEEDBACK VARIABLES
// ──────────────────────────────────────────────────────────────────────────────
$successMessage    = '';   // shown at top for Create‐User events 
$errorMessage      = '';   // shown at top for Create‐User or ListUsers failures
$lastResendUin     = null; // the UIN for which “Resend SMS” was clicked
$lastResendError   = '';   // specific error message for that “Resend SMS” attempt

// ──────────────────────────────────────────────────────────────────────────────
//  1) HANDLE “CREATE USER” AND “RESEND SMS” SUBMISSIONS
// ──────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1a) CREATE A NEW SUB‐USER
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username'] ?? '');
        $rawPhone = trim($_POST['phone']    ?? '');
        $email    = trim($_POST['email']    ?? '');

        // 1a‐i) Basic validation
        if ($username === '' || $rawPhone === '' || $email === '') {
            $errorMessage = '❌ Username, phone, and email are all required.';
        } else {
            // 1a‐ii) Parse "+<CountryCode><SubscriberNumber>".
            //      We allow CountryCode = 1–3 digits, SubscriberNumber = 4–14 digits.
            if (preg_match('/^\+(\d{1,3})(\d{4,14})$/', $rawPhone, $matches)) {
                $countryCode = $matches[1];
                $phoneNum    = $matches[2];
            } else {
                $errorMessage = '❌ Invalid phone format. Use +<CountryCode><Number>, e.g. +8613700000000.';
            }
        }

        // 1a‐iii) If no parse error so far, call AddUser + SendUserMobileCode
        if ($errorMessage === '') {
            try {
                // a) Create sub‐user with phone + country code
                $addReq = new AddUserRequest();
                $addReq->fromJsonString(json_encode([
                    'Name'        => $username,
                    'PhoneNum'    => $phoneNum,
                    'Email'       => $email,
                    'CountryCode' => $countryCode,
                    // By supplying PhoneNum+CountryCode, sub‐user is auto‐granted “Receive Messages.”
                ]));
                $addResp = $client->AddUser($addReq);
                $newUin   = $addResp->Uin;

                // b) Immediately send mobile‐confirmation SMS
                $smsReq = new SendUserMobileCodeRequest();
                $smsReq->fromJsonString(json_encode([
                    'Uin' => intval($newUin),
                ]));
                $client->SendUserMobileCode($smsReq);

                $successMessage = "✅ Sub‐user '{$username}' created (UIN: {$newUin}), SMS sent to {$rawPhone}.";
            } catch (TencentCloudSDKException $e) {
                $errorMessage = '❌ Error creating user or sending SMS: ' . $e->getErrorMessage();
            } catch (Exception $e) {
                $errorMessage = '❌ Unexpected error: ' . $e->getMessage();
            }
        }
    }

    // 1b) RESEND MOBILE‐CONFIRMATION SMS FOR AN EXISTING UIN
    if (isset($_POST['resend_sms']) && is_numeric($_POST['uin'] ?? null)) {
        $lastResendUin = intval($_POST['uin']);
        try {
            $smsReq = new SendUserMobileCodeRequest();
            $smsReq->fromJsonString(json_encode([
                'Uin' => $lastResendUin,
            ]));
            $client->SendUserMobileCode($smsReq);
            // If no exception, inline success. We leave $lastResendError = ''.
        } catch (TencentCloudSDKException $e) {
            $lastResendError = $e->getErrorMessage();
        } catch (Exception $e) {
            $lastResendError = $e->getMessage();
        }
        // Note: We do NOT populate $successMessage/$errorMessage here,
        // so only inline feedback appears under the relevant row.
    }
}

// ──────────────────────────────────────────────────────────────────────────────
//  2) LIST ALL SUB‐USERS (to populate the table)
// ──────────────────────────────────────────────────────────────────────────────
$allUsers = [];
try {
    $listReq  = new ListUsersRequest();
    $listReq->fromJsonString(json_encode(new \stdClass()));  // no filters
    $listResp = $client->ListUsers($listReq);
    if (isset($listResp->Data) && is_array($listResp->Data)) {
        $allUsers = $listResp->Data;
    }
} catch (TencentCloudSDKException $e) {
    $errorMessage = $errorMessage === ''
        ? '❌ Error fetching users: ' . $e->getErrorMessage()
        : $errorMessage;
} catch (Exception $e) {
    $errorMessage = $errorMessage === ''
        ? '❌ Unexpected error listing users: ' . $e->getMessage()
        : $errorMessage;
}

// If no sub‐users and no prior error, show a warning:
if (empty($allUsers) && $errorMessage === '') {
    $errorMessage = '⚠️ No sub‐users found (or an issue listing them).';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CAM Sub‐User Manager</title>
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
    >
</head>
<body class="bg-light">
  <div class="container py-5">

    <!-- ────────────────────────────────────────────────────────── -->
    <!-- TOP‐OF‐PAGE BANNERS (for Create‐User successes/errors) -->
    <!-- ────────────────────────────────────────────────────────── -->
    <?php if ($successMessage): ?>
      <div class="alert alert-success">
        <?= htmlspecialchars($successMessage) ?>
      </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
      <div class="alert alert-danger">
        <?= htmlspecialchars($errorMessage) ?>
      </div>
    <?php endif; ?>

    <!-- ────────────────────────────────────────────────────────── -->
    <!-- 1) ADD SUB‐USER FORM -->
    <!-- ────────────────────────────────────────────────────────── -->
    <div class="card shadow mb-5">
      <div class="card-body">
        <h2 class="card-title text-center mb-4">Add Sub‐User</h2>
        <form method="POST">
          <input type="hidden" name="create_user" value="1">

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Username</label>
              <input
                type="text"
                name="username"
                class="form-control"
                placeholder="e.g. johndoe"
                required
              >
            </div>
            <div class="col-md-4">
              <label class="form-label">Phone Number</label>
              <input
                type="text"
                name="phone"
                class="form-control"
                placeholder="e.g. +8613700000000"
                required
              >
              <div class="form-text">
                Include “+<CountryCode>”, e.g. <code>+1XXXXXXXXXX</code> or <code>+8613700000000</code>.
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Email Address</label>
              <input
                type="email"
                name="email"
                class="form-control"
                placeholder="e.g. bob@example.com"
                required
              >
            </div>
          </div>

          <div class="text-end mt-4">
            <button type="submit" class="btn btn-primary">Create Sub‐User</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ────────────────────────────────────────────────────────── -->
    <!-- 2) SUB‐USER TABLE W/ “RESEND SMS” BUTTON & INLINE FEEDBACK -->
    <!-- ────────────────────────────────────────────────────────── -->
    <div class="card shadow">
      <div class="card-body">
        <h4 class="card-title mb-3">Current Sub‐Users</h4>

        <div class="table-responsive">
          <table class="table table-hover align-middle text-center">
            <thead class="table-dark">
              <tr>
                <th>UIN</th>
                <th>Username</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Resend SMS</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($allUsers)): ?>
                <?php foreach ($allUsers as $user): ?>
                  <tr>
                    <td><?= htmlspecialchars($user->Uin) ?></td>
                    <td><?= htmlspecialchars($user->Name) ?></td>
                    <td><?= htmlspecialchars($user->Email) ?></td>
                    <td>
                      <?php
                        // We stored PhoneNum without “+”; re‐display as "+<CountryCode><PhoneNum>"
                        if (!empty($user->PhoneNum) && !empty($user->CountryCode)) {
                            echo '+' . htmlspecialchars($user->CountryCode)
                                 . htmlspecialchars($user->PhoneNum);
                        } else {
                            echo '<span class="text-muted">N/A</span>';
                        }
                      ?>
                    </td>
                    <td>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="resend_sms" value="1">
                        <input type="hidden" name="uin" value="<?= htmlspecialchars($user->Uin) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success">
                          Resend SMS
                        </button>
                      </form>

                      <?php if ($lastResendUin !== null && $user->Uin == $lastResendUin): ?>
                        <?php if ($lastResendError === ''): ?>
                          <div class="mt-1 text-success small">
                            SMS sent successfully.
                          </div>
                        <?php else: ?>
                          <div class="mt-1 text-danger small">
                            Error: <?= htmlspecialchars($lastResendError) ?>
                          </div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="text-muted">No sub‐users to display.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</body>
</html>
