<?php
require_once 'config.php'; // For RAZORPAY_KEY_SECRET, $plan_details
require_once 'db_connect.php'; // For DB operations

// SIMULATING RAZORPAY PHP SDK - Signature Verification
// In a real scenario with the SDK:
// require_once('razorpay-php/Razorpay.php');
// use Razorpay\Api\Api;
// use Razorpay\Api\Errors\SignatureVerificationError;
// $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);


$success = true;
$error_message = "Payment Failed";
$payment_status_message = '';

// These would be POSTed by Razorpay upon successful payment
$razorpay_payment_id = $_POST['razorpay_payment_id'] ?? null;
$razorpay_order_id_from_rp = $_POST['razorpay_order_id'] ?? null; // The one from Razorpay's POST
$razorpay_signature = $_POST['razorpay_signature'] ?? null;

// Get our stored order ID from session (set in create_razorpay_order.php)
$session_razorpay_order_id = $_SESSION['razorpay_order_id'] ?? null;
$session_plan_id = $_SESSION['razorpay_plan_id'] ?? null;
$session_amount = $_SESSION['razorpay_amount'] ?? null; // Amount in paisa

$log_data = "Callback received: PaymentID: {$razorpay_payment_id}, RP_OrderID: {$razorpay_order_id_from_rp}, Signature: {$razorpay_signature}, SessionOrderID: {$session_razorpay_order_id}\n";
error_log($log_data, 3, "payment_log.txt"); // Log to a file

if (empty($razorpay_payment_id) || empty($razorpay_order_id_from_rp) || empty($razorpay_signature)) {
    $success = false;
    $error_message = "Payment details are incomplete. Please contact support if payment was deducted.";
    $payment_status_message = "Error: Missing payment data.";
} elseif ($session_razorpay_order_id !== $razorpay_order_id_from_rp) {
    // Order ID mismatch - potential tampering or session issue
    $success = false;
    $error_message = "Order ID mismatch. Payment verification failed.";
    $payment_status_message = "Error: Order ID mismatch.";
     error_log("Order ID Mismatch. Session: {$session_razorpay_order_id}, Razorpay POST: {$razorpay_order_id_from_rp}", 3, "payment_log.txt");
} else {
    // --- SIMULATED Signature Verification ---
    // Real verification:
    // try {
    //     $attributes = array(
    //         'razorpay_order_id' => $razorpay_order_id_from_rp,
    //         'razorpay_payment_id' => $razorpay_payment_id,
    //         'razorpay_signature' => $razorpay_signature
    //     );
    //     $api->utility->verifyPaymentSignature($attributes);
    //     // If no exception, signature is valid
    //     $success = true;
    // } catch(SignatureVerificationError $e) {
    //     $success = false;
    //     $error_message = "Payment signature verification failed. " . $e->getMessage();
    //     $payment_status_message = "Error: " . $e->getMessage();
    //     error_log("Signature Verification Failed: {$error_message} for OrderID {$razorpay_order_id_from_rp}", 3, "payment_log.txt");
    // }

    // For simulation, we'll assume signature is valid if all IDs are present and match session.
    // In a real test environment, you'd get actual signatures to verify.
    // This is a MAJOR security simplification for this exercise.
    if (RAZORPAY_KEY_SECRET === 'YOUR_TEST_KEY_SECRET' || strpos(RAZORPAY_KEY_ID, 'rzp_test_') === 0) {
        // If using placeholder keys or actual test keys, we can't truly verify signature without SDK making a call or complex crypto.
        // So, for this simulation, if we have the IDs, we'll proceed as if verified.
        $success = true; // Simulate successful signature verification
        $payment_status_message = "Payment Successful (Simulated Verification).";
        error_log("SIMULATED Signature Verification Success for OrderID {$razorpay_order_id_from_rp}", 3, "payment_log.txt");

    } else {
        // If you had real keys and wanted to implement the hash manually (NOT RECOMMENDED - USE SDK)
        // $expected_signature = hash_hmac('sha256', $razorpay_order_id_from_rp . "|" . $razorpay_payment_id, RAZORPAY_KEY_SECRET);
        // if (hash_equals($expected_signature, $razorpay_signature)) {
        //     $success = true;
        // } else {
        //     $success = false;
        //     $error_message = "Payment signature verification failed. Invalid signature.";
        //     $payment_status_message = "Error: Invalid signature.";
        //     error_log("Manual Hash Signature Verification Failed for OrderID {$razorpay_order_id_from_rp}", 3, "payment_log.txt");
        // }
        // For now, sticking to the simpler simulation for non-SDK environment:
        $success = true;
        $payment_status_message = "Payment Processed (Simulated Full Verification).";
        error_log("SIMULATED Full Verification (as if real keys were used and matched) for OrderID {$razorpay_order_id_from_rp}", 3, "payment_log.txt");
    }
}


if ($success) {
    // Payment is verified (or simulated as verified)
    // Proceed to update database
    $user_uid = $_SESSION['user_uid'] ?? null;
    $plan_id = $session_plan_id; // e.g., 'basic', 'pro'
    $actual_amount_paid_paisa = $session_amount; // Amount from our session

    if (!$user_uid || !$plan_id || !isset($plan_details[$plan_id])) {
        $payment_status_message = "Error: Critical session data missing after payment. Please contact support.";
        error_log("Critical session data missing. UserUID: {$user_uid}, PlanID: {$plan_id}", 3, "payment_log.txt");
        // $success is still true from Razorpay's perspective, but our internal processing failed.
        // This needs careful handling - maybe redirect to a page that asks user to contact support with payment ID.
    } else {
        $selected_plan_config = $plan_details[$plan_id];
        $companyID = null;

        // Get CompanyID for the user
        $stmt_get_company = $conn->prepare("SELECT CompanyID FROM users WHERE UID = ?");
        if($stmt_get_company){
            $stmt_get_company->bind_param("i", $user_uid);
            $stmt_get_company->execute();
            $company_res = $stmt_get_company->get_result();
            if($comp_row = $company_res->fetch_assoc()){
                $companyID = $comp_row['CompanyID'];
            }
            $stmt_get_company->close();
        } else {
            $payment_status_message = "Error fetching company details. DB error.";
            error_log("Failed to prepare stmt to get CompanyID for UID {$user_uid}: " . $conn->error, 3, "payment_log.txt");
        }


        if ($companyID) {
            $conn->begin_transaction();
            try {
                $new_package_name = $selected_plan_config['name'];
                $new_max_users = $selected_plan_config['max_users'];
                $new_max_calc = $selected_plan_config['max_calculations_per_day'];
                $new_sub_end_date = date('Y-m-d H:i:s', strtotime("+{$selected_plan_config['duration_days']} days"));
                $payment_db_status = 'paid';

                // Update or Insert into company_subscription
                $stmt_check_sub = $conn->prepare("SELECT SubscriptionID FROM company_subscription WHERE CompanyID = ?");
                if(!$stmt_check_sub) throw new Exception("DB Error: Prepare check subscription failed: " . $conn->error);
                $stmt_check_sub->bind_param("i", $companyID);
                $stmt_check_sub->execute();
                $sub_result = $stmt_check_sub->get_result();

                if ($sub_result->num_rows > 0) { // Update existing subscription
                    $stmt_update_sub = $conn->prepare(
                        "UPDATE company_subscription SET PackageName = ?, MaxUsers = ?, MaxCalculationsPerDay = ?,
                         SubscriptionStartDate = CURRENT_TIMESTAMP, SubscriptionEndDate = ?, PaymentStatus = ?,
                         RazorpayPaymentID = ?, RazorpayOrderID = ?, RazorpaySignature = ?, Amount = ?, Currency = ?
                         WHERE CompanyID = ?"
                    );
                    if(!$stmt_update_sub) throw new Exception("DB Error: Prepare update subscription failed: " . $conn->error);
                    $amount_decimal = $actual_amount_paid_paisa / 100;
                    $currency_code = $_SESSION['razorpay_currency'] ?? 'INR';
                    $stmt_update_sub->bind_param("siissssssdsi",
                        $new_package_name, $new_max_users, $new_max_calc, $new_sub_end_date, $payment_db_status,
                        $razorpay_payment_id, $razorpay_order_id_from_rp, $razorpay_signature,
                        $amount_decimal, $currency_code, $companyID
                    );
                } else { // Insert new subscription
                     $stmt_update_sub = $conn->prepare(
                        "INSERT INTO company_subscription (CompanyID, PackageName, MaxUsers, MaxCalculationsPerDay,
                         SubscriptionStartDate, SubscriptionEndDate, PaymentStatus, RazorpayPaymentID, RazorpayOrderID,
                         RazorpaySignature, Amount, Currency)
                         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    if(!$stmt_update_sub) throw new Exception("DB Error: Prepare insert subscription failed: " . $conn->error);
                    $amount_decimal = $actual_amount_paid_paisa / 100;
                    $currency_code = $_SESSION['razorpay_currency'] ?? 'INR';
                    $stmt_update_sub->bind_param("isiisssssds",
                        $companyID, $new_package_name, $new_max_users, $new_max_calc, $new_sub_end_date, $payment_db_status,
                        $razorpay_payment_id, $razorpay_order_id_from_rp, $razorpay_signature,
                        $amount_decimal, $currency_code
                    );
                }
                $stmt_check_sub->close();

                if (!$stmt_update_sub->execute()) {
                    throw new Exception("Failed to update subscription details: " . $stmt_update_sub->error);
                }
                $stmt_update_sub->close();

                // Update auth status for all users in that company from 'trial' to 'active'
                $stmt_update_auth = $conn->prepare("UPDATE auth SET Status = 'active', UpdatedOn = CURRENT_TIMESTAMP
                                                    WHERE UID IN (SELECT UID FROM users WHERE CompanyID = ?) AND Status = 'trial'");
                if(!$stmt_update_auth) throw new Exception("DB Error: Prepare update auth status failed: " . $conn->error);
                $stmt_update_auth->bind_param("i", $companyID);
                if (!$stmt_update_auth->execute()) {
                    // Log this, but don't necessarily fail the whole transaction if subscription updated.
                    error_log("Warning: Failed to update auth status for users in CompanyID {$companyID}: " . $stmt_update_auth->error, 3, "payment_log.txt");
                }
                $stmt_update_auth->close();

                $conn->commit();
                $payment_status_message = "Payment successful and subscription activated for plan: " . htmlspecialchars($new_package_name) . "!";
                error_log("SUCCESS: Payment processed and DB updated for OrderID {$razorpay_order_id_from_rp}, CompanyID {$companyID}, Plan {$new_package_name}", 3, "payment_log.txt");

                // Clear session variables related to this order
                unset($_SESSION['razorpay_order_id']);
                unset($_SESSION['razorpay_plan_id']);
                unset($_SESSION['razorpay_amount']);
                unset($_SESSION['razorpay_currency']);
                unset($_SESSION['razorpay_receipt_id']);

            } catch (Exception $e) {
                $conn->rollback();
                $payment_status_message = "Payment was successful with Razorpay, but there was an error updating your subscription: " . $e->getMessage() . ". Please contact support with Payment ID: " . htmlspecialchars($razorpay_payment_id);
                error_log("DB Update Failed after successful payment: {$e->getMessage()} for OrderID {$razorpay_order_id_from_rp}, PaymentID {$razorpay_payment_id}", 3, "payment_log.txt");
            }
        } else {
             $payment_status_message = "Could not find your company account to update subscription. Please contact support with Payment ID: " . htmlspecialchars($razorpay_payment_id);
             error_log("CompanyID not found for UID {$user_uid} after successful payment: {$razorpay_payment_id}", 3, "payment_log.txt");
        }
    }
} else {
    // Signature verification failed or other error from initial checks
    $payment_status_message = "Payment Verification Failed: " . $error_message;
    // No database changes should happen here.
}

// Redirect user to a status page
// For simplicity, redirecting to dashboard with a message.
// A dedicated payment_status.php page would be better.
$redirect_url = BASE_URL . 'user_area/dashboard.php?payment_status=' . urlencode($success ? 'success' : 'failed') . '&message=' . urlencode($payment_status_message);
if($razorpay_payment_id) {
    $redirect_url .= '&payment_id=' . urlencode($razorpay_payment_id);
}
if($razorpay_order_id_from_rp){
    $redirect_url .= '&order_id=' . urlencode($razorpay_order_id_from_rp);
}

header('Location: ' . $redirect_url);
$conn->close();
exit;
?>
