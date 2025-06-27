<?php
session_start(); // Ensure session is started so it can be destroyed

// Unset all of the session variables.
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session.
session_destroy();

// Clear the user token cookie
if (isset($_COOKIE['user_token'])) {
    unset($_COOKIE['user_token']);
    // Set its expiration date to the past to trigger removal
    setcookie('user_token', '', time() - 3600, '/');
}

// Redirect to login page with a message
header("Location: login.php?message=" . urlencode("You have been logged out successfully."));
exit;
?>
