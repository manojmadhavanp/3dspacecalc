<?php
// Database Configuration (already in db_connect.php, but can be centralized)
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'saas_platform');

// Razorpay API Keys (Replace with your actual test keys)
// IMPORTANT: For production, use environment variables, not hardcoded keys.
define('RAZORPAY_KEY_ID', 'YOUR_TEST_KEY_ID'); // Example: rzp_test_12345
define('RAZORPAY_KEY_SECRET', 'YOUR_TEST_KEY_SECRET'); // Example: abcXYZ123

// Application Settings
define('BASE_URL', 'http://localhost/saas_platform/'); // Adjust as per your local setup
define('APP_NAME', 'FreightCalc Solutions');

// Email Configuration (for password resets, notifications etc. - placeholders)
define('MAIL_FROM', 'noreply@example.com');
define('MAIL_FROM_NAME', APP_NAME);

// Role Unique Identifiers (RUIDs) - good to have them defined
define('ROLE_ADMIN_RUID', 'ADMIN_ROLE_UID');
define('ROLE_COMPANY_ADMIN_RUID', 'COMPANY_ADMIN_ROLE_UID');
define('ROLE_USER_RUID', 'USER_ROLE_UID');
define('ROLE_TRIAL_USER_RUID', 'TRIAL_USER_ROLE_UID');


// Ensure error reporting is suitable for environment
// For development:
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// For production, you'd typically set display_errors to 0 and log errors.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Function to generate a unique ID (UUID v4) - if not already in db_connect or elsewhere
if (!function_exists('generateUUID')) {
    function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Define plan details (prices in paisa for Razorpay)
// These could also be fetched from a database table for more dynamic pricing
$plan_details = [
    'basic' => [
        'id' => 'plan_basic_monthly', // Your internal plan ID
        'name' => 'Basic Plan',
        'amount_paisa' => 99900, // ₹999.00
        'currency' => 'INR',
        'max_users' => 3,
        'max_calculations_per_day' => 10,
        'duration_days' => 30 // For subscription end date calculation
    ],
    'pro' => [
        'id' => 'plan_pro_monthly',
        'name' => 'Pro Plan',
        'amount_paisa' => 199900, // ₹1999.00
        'currency' => 'INR',
        'max_users' => 10,
        'max_calculations_per_day' => 50,
        'duration_days' => 30
    ],
    // Add other plans (e.g., annual versions)
];

?>
