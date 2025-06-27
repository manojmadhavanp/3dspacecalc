<?php
require_once 'db_connect.php'; // Defines $conn and generateUUID() if needed, though bin2hex(random_bytes(32)) is better for tokens

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: forgot_password.php?error=" . urlencode("Please enter a valid email address."));
        exit;
    }

    // Check if user with this email exists
    $stmt = $conn->prepare("SELECT UID FROM users WHERE Email = ?");
    if (!$stmt) {
        // Log detailed error
        error_log("DB Prepare Error (User Check): " . $conn->error);
        header("Location: forgot_password.php?error=" . urlencode("An error occurred. Please try again."));
        exit;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $userID = $user['UID'];

        // Generate a unique token
        $token = bin2hex(random_bytes(32)); // More secure for tokens than UUID
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour

        // Store the token in the password_resets table
        // First, invalidate any existing tokens for this user to be safe
        $invalidateStmt = $conn->prepare("UPDATE password_resets SET Used = TRUE WHERE UID = ? AND Used = FALSE");
        if($invalidateStmt){
            $invalidateStmt->bind_param("i", $userID);
            $invalidateStmt->execute();
            $invalidateStmt->close();
        } else {
            error_log("DB Prepare Error (Invalidate old tokens): " . $conn->error);
            // Non-critical, proceed
        }


        $insertStmt = $conn->prepare("INSERT INTO password_resets (UID, Token, ExpiresAt) VALUES (?, ?, ?)");
        if (!$insertStmt) {
            // Log detailed error
            error_log("DB Prepare Error (Token Insert): " . $conn->error);
            header("Location: forgot_password.php?error=" . urlencode("An error occurred generating reset link. Please try again."));
            exit;
        }
        $insertStmt->bind_param("iss", $userID, $token, $expiresAt);

        if ($insertStmt->execute()) {
            // Simulate sending email
            // In a real application, you would use a mail library (PHPMailer, SwiftMailer, etc.)
            // $resetLink = "https://yourdomain.com/reset_password.php?token=" . $token;
            // mail($email, "Password Reset Request", "Click here to reset your password: " . $resetLink);

            // For this simulation, we'll pass the token back to the page to display it.
            // THIS IS NOT SECURE FOR PRODUCTION.
            $success_message = "If an account with that email exists, a password reset link has been sent (simulated).";
            header("Location: forgot_password.php?success=" . urlencode($success_message) . "&token_info=" . urlencode($token));
            exit;

        } else {
            // Log detailed error
            error_log("DB Execute Error (Token Insert): " . $insertStmt->error);
            header("Location: forgot_password.php?error=" . urlencode("Could not process password reset request. Please try again."));
            exit;
        }
        $insertStmt->close();
    } else {
        // Email not found, show a generic message to prevent user enumeration
        $success_message = "If an account with that email exists, a password reset link has been sent (simulated).";
        header("Location: forgot_password.php?success=" . urlencode($success_message));
        exit;
    }
    $stmt->close();
    $conn->close();

} else {
    header("Location: forgot_password.php");
    exit;
}
?>
