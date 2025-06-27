<?php
require_once 'db_connect.php'; // Defines $conn

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function validatePassword($password) {
    return strlen($password) >= 8 && preg_match("/[A-Za-z]/", $password) && preg_match("/[0-9]/", $password);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $token = trim($_POST['token']);
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    if (empty($token)) {
        header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Reset token is missing."));
        exit;
    }

    if (empty($newPassword) || empty($confirmPassword)) {
        header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Please fill in both password fields."));
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Passwords do not match."));
        exit;
    }

    if (!validatePassword($newPassword)) {
        header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Password must be at least 8 characters long and include letters and numbers."));
        exit;
    }

    // Validate the token
    $stmt = $conn->prepare("SELECT UID, ExpiresAt, Used FROM password_resets WHERE Token = ?");
    if (!$stmt) {
        error_log("DB Prepare Error (Token Validate): " . $conn->error);
        header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Error validating token. Please try again."));
        exit;
    }
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $resetRequest = $result->fetch_assoc();
        $userID = $resetRequest['UID'];

        if ($resetRequest['Used']) {
            header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("This reset link has already been used."));
            $stmt->close();
            $conn->close();
            exit;
        }

        if (strtotime($resetRequest['ExpiresAt']) < time()) {
            header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("This reset link has expired. Please request a new one."));
            $stmt->close();
            $conn->close();
            exit;
        }

        // Token is valid, not used, and not expired. Proceed to update password.
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            // Update password in auth table
            $updateAuthStmt = $conn->prepare("UPDATE auth SET PasswordEncrypted = ?, UpdatedOn = CURRENT_TIMESTAMP WHERE UID = ?");
            if (!$updateAuthStmt) throw new Exception("Auth update prepare failed: " . $conn->error);
            $updateAuthStmt->bind_param("si", $hashedPassword, $userID);
            if (!$updateAuthStmt->execute()) throw new Exception("Auth update execution failed: " . $updateAuthStmt->error);
            $updateAuthStmt->close();

            // Mark token as used
            $updateTokenStmt = $conn->prepare("UPDATE password_resets SET Used = TRUE WHERE Token = ?");
            if (!$updateTokenStmt) throw new Exception("Token update prepare failed: " . $conn->error);
            $updateTokenStmt->bind_param("s", $token);
            if (!$updateTokenStmt->execute()) throw new Exception("Token update execution failed: " . $updateTokenStmt->error);
            $updateTokenStmt->close();

            $conn->commit();
            header("Location: reset_password.php?success=" . urlencode("Password has been reset successfully. You can now login."));
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Password Reset Error: " . $e->getMessage());
            header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Failed to reset password. Please try again."));
            exit;
        }

    } else {
        header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Invalid or expired reset link."));
        exit;
    }
    $stmt->close();
    $conn->close();

} else {
    // Not a POST request, redirect based on whether token is present or not
    if(isset($_GET['token']) && !empty($_GET['token'])) {
        header("Location: reset_password.php?token=" . urlencode($_GET['token']));
    } else {
        header("Location: forgot_password.php?error=" . urlencode("Invalid access to reset page."));
    }
    exit;
}
?>
