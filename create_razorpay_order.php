<?php
require_once 'config.php'; // For RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, $plan_details
require_once 'db_connect.php'; // For DB access if needed to fetch user/company details

header('Content-Type: application/json');

if (!isset($_SESSION['user_uuid'])) {
    echo json_encode(['error' => 'User not logged in.']);
    exit;
}

$plan_id_from_request = $_POST['plan_id'] ?? null; // e.g., 'basic', 'pro'

if (!$plan_id_from_request || !isset($plan_details[$plan_id_from_request])) {
    echo json_encode(['error' => 'Invalid plan selected.']);
    exit;
}

$selected_plan = $plan_details[$plan_id_from_request];
$amount_paisa = $selected_plan['amount_paisa'];
$currency = $selected_plan['currency'];
$receipt_id = 'RCPT_' . time() . '_' . strtoupper($plan_id_from_request); // Unique receipt ID

// Fetch user and company details for Razorpay order
$user_email = '';
$user_phone = '';
$company_name = '';
$user_uid = $_SESSION['user_uid'] ?? null;

if ($user_uid) {
    $stmt = $conn->prepare("SELECT u.Email as UserEmail, u.PhoneNumber as UserPhone, c.CompanyName
                            FROM users u
                            JOIN company c ON u.CompanyID = c.CompanyID
                            WHERE u.UID = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_uid);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($details = $result->fetch_assoc()) {
            $user_email = $details['UserEmail'];
            $user_phone = $details['UserPhone'];
            $company_name = $details['CompanyName'];
        }
        $stmt->close();
    } else {
        error_log("DB error fetching user/company for Razorpay: " . $conn->error);
    }
}


// SIMULATING RAZORPAY PHP SDK USAGE
// In a real scenario with the SDK installed:
// require_once('razorpay-php/Razorpay.php'); // Path to SDK
// use Razorpay\Api\Api;
// $api = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
//
// $orderData = [
//     'receipt'         => $receipt_id,
//     'amount'          => $amount_paisa, // Amount in paisa
//     'currency'        => $currency,
//     'payment_capture' => 1, // Auto capture payment
//     'notes'           => [
//         'plan_name'     => $selected_plan['name'],
//         'user_uid'      => $_SESSION['user_uid'], // Your internal user ID
//         'company_name'  => $company_name
//     ]
// ];
// try {
//     $razorpayOrder = $api->order->create($orderData);
//     $razorpayOrderId = $razorpayOrder['id'];
//
//     // Store $razorpayOrderId, $plan_id_from_request, $_SESSION['user_uid'], $amount_paisa in session or temp DB table
//     // to verify against it in payment_verification.php
//     $_SESSION['razorpay_order_id'] = $razorpayOrderId;
//     $_SESSION['razorpay_plan_id'] = $plan_id_from_request; // Store which plan this order is for
//     $_SESSION['razorpay_amount'] = $amount_paisa;
//
//     echo json_encode([
//         'success' => true,
//         'order_id' => $razorpayOrderId,
//         'amount' => $amount_paisa,
//         'currency' => $currency,
//         'key_id' => RAZORPAY_KEY_ID,
//         'plan_name' => $selected_plan['name'],
//         'company_name' => $company_name, // For prefill
//         'user_email' => $user_email,     // For prefill
//         'user_phone' => $user_phone      // For prefill
//     ]);
//
// } catch (Exception $e) {
//     echo json_encode(['error' => 'Razorpay Error: ' . $e->getMessage()]);
//     exit;
// }


// --- SIMULATION RESPONSE (since SDK is not installed via composer) ---
$simulatedRazorpayOrderId = 'order_sim_' . generateUUID(); // Simulated Order ID

// Store necessary info in session for verification on callback
$_SESSION['razorpay_order_id'] = $simulatedRazorpayOrderId;
$_SESSION['razorpay_plan_id'] = $plan_id_from_request;
$_SESSION['razorpay_amount'] = $amount_paisa;
$_SESSION['razorpay_currency'] = $currency; // Store currency too
$_SESSION['razorpay_receipt_id'] = $receipt_id; // Store receipt


echo json_encode([
    'success' => true,
    'order_id' => $simulatedRazorpayOrderId,
    'amount' => $amount_paisa,
    'currency' => $currency,
    'key_id' => RAZORPAY_KEY_ID, // Test Key ID
    'plan_name' => $selected_plan['name'],
    'company_name' => $company_name ?: APP_NAME, // Prefill info
    'user_email' => $user_email,
    'user_phone' => $user_phone,
    'description' => 'Payment for ' . $selected_plan['name'] . ' on ' . APP_NAME,
    'callback_url' => BASE_URL . 'payment_verification.php', // Important for Razorpay
    'prefill' => [
        'name' => $_SESSION['user_first_name'] ?? 'Valued Customer', // Assuming user_first_name is in session
        'email' => $user_email,
        'contact' => $user_phone
    ],
    'notes' => [
        'address' => 'Corporate Office', // Example note
        'plan_internal_id' => $selected_plan['id']
    ],
    'theme' => [
        'color' => '#007bff' // Theme color for checkout
    ]
]);

$conn->close();
exit;
?>
