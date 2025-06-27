<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in by checking session variables set during login
if (!isset($_SESSION['user_uuid']) || !isset($_SESSION['user_role_ruid'])) {
    // Not logged in, redirect to login page
    // Pass a message to login page if desired
    header("Location: ../login.php?error=" . urlencode("You must be logged in to access this page."));
    exit;
}

// Optionally, you could re-verify the user against the database here for added security,
// but for basic session validation, checking existence of session variables is common.

// You can also retrieve user details from session or cookie if needed for display
$user_first_name = "User"; // Default
if (isset($_COOKIE['user_token'])) {
    $token_data = json_decode($_COOKIE['user_token'], true);
    if (isset($token_data['firstName'])) {
        $user_first_name = htmlspecialchars($token_data['firstName']);
    }
} elseif (isset($_SESSION['user_first_name'])) { // Fallback if cookie not there but session has it
    $user_first_name = htmlspecialchars($_SESSION['user_first_name']);
}

// You might want to store more user details in the session during login to avoid frequent cookie parsing or DB hits
// For example, $_SESSION['user_first_name'], $_SESSION['user_company_id'] etc.
// For now, we primarily rely on the cookie for display name as per original spec, and session for auth state.

// The $user_first_name can be used in the pages that include this file.
?>
