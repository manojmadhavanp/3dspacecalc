<?php
session_start();
require_once 'db_connect.php'; // Defines $conn and generateUUID()

// Error reporting for development (remove or adjust for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function validatePassword($password) {
    // Example: Minimum 8 characters, at least one letter and one number
    // You can make this more complex
    return strlen($password) >= 8 && preg_match("/[A-Za-z]/", $password) && preg_match("/[0-9]/", $password);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // User details
    $firstName = trim($_POST['firstName']);
    $middleName = trim($_POST['middleName']);
    $lastName = trim($_POST['lastName']);
    $userEmail = trim($_POST['userEmail']);
    $userMobile = trim($_POST['userMobile']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirmPassword'];

    // Company details
    $companyName = trim($_POST['companyName']);
    $companyEmail = trim($_POST['companyEmail']);
    $companyPhone = trim($_POST['companyPhone']);
    $companyAddress = trim($_POST['companyAddress']);

    // --- Validations ---
    if (empty($firstName) || empty($lastName) || empty($userEmail) || empty($userMobile) || empty($password) || empty($companyName)) {
        header("Location: register.php?error=" . urlencode("All required fields must be filled."));
        exit;
    }

    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        header("Location: register.php?error=" . urlencode("Invalid user email format."));
        exit;
    }
    if (!empty($companyEmail) && !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
        header("Location: register.php?error=" . urlencode("Invalid company email format."));
        exit;
    }

    if ($password !== $confirmPassword) {
        header("Location: register.php?error=" . urlencode("Passwords do not match."));
        exit;
    }

    if (!validatePassword($password)) {
        header("Location: register.php?error=" . urlencode("Password must be at least 8 characters long and include letters and numbers."));
        exit;
    }

    // Use companyEmail if provided, otherwise use userEmail for the company
    $finalCompanyEmail = !empty($companyEmail) ? $companyEmail : $userEmail;


    // --- Check for existing user/company ---
    $stmt = $conn->prepare("SELECT UID FROM users WHERE Email = ? OR PhoneNumber = ?");
    if (!$stmt) {
        header("Location: register.php?error=" . urlencode("Database error (user check prepare): " . $conn->error));
        exit;
    }
    $stmt->bind_param("ss", $userEmail, $userMobile);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        header("Location: register.php?error=" . urlencode("User with this email or mobile already exists."));
        $stmt->close();
        exit;
    }
    $stmt->close();

    $companyUID = "COMP-" . generateUUID(); // Generate a unique Company UID

    $stmt = $conn->prepare("SELECT CompanyID FROM company WHERE CompanyName = ? OR Email = ? OR CompanyUID = ?");
     if (!$stmt) {
        header("Location: register.php?error=" . urlencode("Database error (company check prepare): " . $conn->error));
        exit;
    }
    $stmt->bind_param("sss", $companyName, $finalCompanyEmail, $companyUID);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        // Check which one exists - this is a simplified check
        header("Location: register.php?error=" . urlencode("Company with this name, email, or UID already exists."));
        $stmt->close();
        exit;
    }
    $stmt->close();


    // --- Start Transaction ---
    $conn->begin_transaction();

    try {
        // 1. Insert into company table
        $stmt = $conn->prepare("INSERT INTO company (CompanyName, CompanyUID, PhoneNumber, Email, Address) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception("Company insert prepare failed: " . $conn->error);
        $stmt->bind_param("sssss", $companyName, $companyUID, $companyPhone, $finalCompanyEmail, $companyAddress);
        if (!$stmt->execute()) throw new Exception("Company insert execution failed: " . $stmt->error);
        $companyID = $stmt->insert_id; // Get the new company ID
        $stmt->close();

        // 2. Get 'CompanyAdmin' Role ID for the first user of the company.
        // This user will manage the company. Their trial status is handled by `auth.Status` and `company_subscription`.
        $companyAdminRoleRUID = 'COMPANY_ADMIN_ROLE_UID';
        $roleStmt = $conn->prepare("SELECT RID FROM roles WHERE RoleRUID = ?");
        if (!$roleStmt) throw new Exception("Role select prepare failed (CompanyAdmin): " . $conn->error);
        $roleStmt->bind_param("s", $companyAdminRoleRUID);
        $roleStmt->execute();
        $roleResult = $roleStmt->get_result();
        if ($roleResult->num_rows === 0) {
            throw new Exception("CompanyAdmin role ('COMPANY_ADMIN_ROLE_UID') not found. Please ensure roles are populated.");
        }
        $roleRow = $roleResult->fetch_assoc();
        $userRoleID = $roleRow['RID']; // This will be the RID for 'CompanyAdmin'
        $roleStmt->close();


        // 3. Insert into users table
        $userUUID = generateUUID(); // Generate a unique User UUID
        $stmt = $conn->prepare("INSERT INTO users (UUID, FirstName, MiddleName, LastName, PhoneNumber, Email, CompanyID, RID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception("User insert prepare failed: " . $conn->error);
        $stmt->bind_param("ssssssii", $userUUID, $firstName, $middleName, $lastName, $userMobile, $userEmail, $companyID, $trialUserRID);
        if (!$stmt->execute()) throw new Exception("User insert execution failed: " . $stmt->error);
        $userID = $stmt->insert_id; // Get the new user ID
        $stmt->close();

        // 4. Insert into auth table
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $status = 'trial'; // Initial status
        $stmt = $conn->prepare("INSERT INTO auth (UID, PasswordEncrypted, Status) VALUES (?, ?, ?)");
        if (!$stmt) throw new Exception("Auth insert prepare failed: " . $conn->error);
        $stmt->bind_param("iss", $userID, $hashedPassword, $status);
        if (!$stmt->execute()) throw new Exception("Auth insert execution failed: " . $stmt->error);
        $stmt->close();

        // 5. Insert into company_subscription for 7-day trial
        $trialEndDate = date('Y-m-d H:i:s', strtotime('+7 days'));
        $packageName = 'trial';
        $maxUsers = 1;
        $maxCalculationsPerDay = 1;
        $paymentStatus = 'active_trial'; // Or just 'trial'

        $stmt = $conn->prepare("INSERT INTO company_subscription (CompanyID, PackageName, MaxUsers, MaxCalculationsPerDay, SubscriptionEndDate, PaymentStatus) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception("Subscription insert prepare failed: " . $conn->error);
        $stmt->bind_param("isssss", $companyID, $packageName, $maxUsers, $maxCalculationsPerDay, $trialEndDate, $paymentStatus);
        if (!$stmt->execute()) throw new Exception("Subscription insert execution failed: " . $stmt->error);
        $stmt->close();

        // If all good, commit the transaction
        $conn->commit();
        header("Location: register.php?success=" . urlencode("Registration successful! You can now log in."));
        exit;

    } catch (Exception $e) {
        $conn->rollback(); // Rollback on any error
        // Log the detailed error for admins, show a generic one to user.
        error_log("Registration Error: " . $e->getMessage());
        header("Location: register.php?error=" . urlencode("Registration failed. Please try again later. Details: " . $e->getMessage()));
        exit;
    }

} else {
    // Not a POST request
    header("Location: register.php");
    exit;
}

$conn->close();
?>
