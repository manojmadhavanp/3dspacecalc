<?php
// Looser error reporting for API, often just JSON response is enough
// ini_set('display_errors', 0);
// error_reporting(0); // Errors should be logged, not displayed in JSON output typically

header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_uuid']) || !isset($_SESSION['user_uid'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please login.']);
    exit;
}

// Optionally, you could add more checks here, e.g., token expiry if using separate API tokens,
// or check user status in DB.

// Make user UID and UUID available to scripts that include this file
$api_user_uid = $_SESSION['user_uid'];
$api_user_uuid = $_SESSION['user_uuid'];

// Function to get CompanyID for the current API user
function getApiUserCompanyID($conn, $user_uid) {
    $companyID = null;
    $stmt = $conn->prepare("SELECT CompanyID FROM users WHERE UID = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_uid);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $companyID = $row['CompanyID'];
        }
        $stmt->close();
    } else {
        error_log("API Auth: Failed to prepare statement to get CompanyID for UID: " . $user_uid . " Error: " . $conn->error);
    }
    return $companyID;
}

// Function to check API usage limits
function checkApiUsageLimits($conn, $companyID) {
    if (!$companyID) {
        return ['allowed' => false, 'message' => 'Could not determine company for usage check.'];
    }

    $stmt_sub = $conn->prepare(
        "SELECT cs.PackageName, cs.MaxCalculationsPerDay, COUNT(s.SearchID) as TodayCalculations
         FROM company_subscription cs
         LEFT JOIN search s ON cs.CompanyID = s.CompanyID AND DATE(s.SearchDateTime) = CURDATE()
         WHERE cs.CompanyID = ?
         GROUP BY cs.PackageName, cs.MaxCalculationsPerDay"
    );

    if($stmt_sub) {
        $stmt_sub->bind_param("i", $companyID);
        $stmt_sub->execute();
        $result_sub = $stmt_sub->get_result();

        if ($sub_details = $result_sub->fetch_assoc()) {
            if (strtolower($sub_details['PackageName']) == 'trial' || $sub_details['MaxCalculationsPerDay'] > 0) {
                if ($sub_details['TodayCalculations'] >= $sub_details['MaxCalculationsPerDay']) {
                    return [
                        'allowed' => false,
                        'message' => "Daily calculation limit of " . $sub_details['MaxCalculationsPerDay'] . " for the '" . htmlspecialchars($sub_details['PackageName']) . "' plan reached."
                    ];
                }
            }
            return ['allowed' => true, 'message' => 'Usage allowed.'];
        } else {
             error_log("API Usage Check: No subscription details found for CompanyID: " . $companyID);
             return ['allowed' => false, 'message' => 'Subscription status could not be verified.'];
        }
        $stmt_sub->close();
    } else {
        error_log("API Usage Check: Failed to prepare subscription check. Error: " . $conn->error);
        return ['allowed' => false, 'message' => 'Error checking usage limits.'];
    }
}

?>
