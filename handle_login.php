<?php
session_start();
require_once 'db_connect.php'; // Defines $conn

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST['identifier']); // Can be email or mobile
    $password = $_POST['password'];

    if (empty($identifier) || empty($password)) {
        header("Location: login.php?error=" . urlencode("Email/Mobile and password are required."));
        exit;
    }

    // Prepare to fetch user details
    // The initial spec mentioned login with "email, mobile or company UID".
    // For now, this implementation supports email or mobile for the user.
    // If CompanyUID is a user-specific login field, the users table schema would need it,
    // or we'd query company first, then its users. Sticking to user's direct identifiers first.
    $stmt = $conn->prepare("SELECT u.UID, u.UUID, u.FirstName, u.LastName, u.RID, a.PasswordEncrypted, a.Status, r.RoleRUID
                            FROM users u
                            JOIN auth a ON u.UID = a.UID
                            JOIN roles r ON u.RID = r.RID
                            WHERE u.Email = ? OR u.PhoneNumber = ?");
    if (!$stmt) {
        header("Location: login.php?error=" . urlencode("Database error (login prepare): " . $conn->error));
        exit;
    }
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['PasswordEncrypted'])) {
            // Password is correct
            if ($user['Status'] == 'inactive' || $user['Status'] == 'suspended') {
                header("Location: login.php?error=" . urlencode("Your account is " . $user['Status'] . ". Please contact support."));
                $stmt->close();
                $conn->close();
                exit;
            }

            // Update LastLogin and LoggedInStatus
            $updateStmt = $conn->prepare("UPDATE auth SET LastLogin = CURRENT_TIMESTAMP, LoggedInStatus = TRUE WHERE UID = ?");
            if (!$updateStmt) {
                 // Log this error, but proceed with login if possible
                error_log("Failed to prepare update for LastLogin: " . $conn->error);
            } else {
                $updateStmt->bind_param("i", $user['UID']);
                if (!$updateStmt->execute()) {
                    error_log("Failed to execute update for LastLogin: " . $updateStmt->error);
                }
                $updateStmt->close();
            }


            // Store information in session
            $_SESSION['user_uuid'] = $user['UUID'];
            $_SESSION['user_role_ruid'] = $user['RoleRUID'];
            $_SESSION['user_uid'] = $user['UID']; // Storing UID might be useful for internal lookups

            // Set cookie for token (UUID, FirstName, LastName)
            // This is a simple example. For production, consider HttpOnly, Secure flags, and possibly a more robust token (JWT)
            $cookie_data = [
                'uuid' => $user['UUID'],
                'firstName' => $user['FirstName'],
                'lastName's' => $user['LastName'] // Typo here, should be 'lastName'
            ];
            // Correcting typo for lastName
            $cookie_data['lastName'] = $user['LastName'];
            unset($cookie_data['lastName's']);


            // Serialize or JSON encode data for cookie. JSON is generally better.
            $cookie_value = json_encode($cookie_data);
            // Expires in 30 days
            setcookie("user_token", $cookie_value, time() + (86400 * 30), "/", "", isset($_SERVER["HTTPS"]), true); // Last TRUE for HttpOnly

            // Redirect to user dashboard
            header("Location: user_area/dashboard.php");
            $stmt->close();
            $conn->close();
            exit;

        } else {
            // Invalid password
            header("Location: login.php?error=" . urlencode("Invalid login credentials."));
            $stmt->close();
            $conn->close();
            exit;
        }
    } else {
        // User not found
        header("Location: login.php?error=" . urlencode("Invalid login credentials."));
        $stmt->close();
        $conn->close();
        exit;
    }
} else {
    header("Location: login.php");
    exit;
}
?>
