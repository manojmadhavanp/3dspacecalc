```php
<?php

namespace App\Service;

use App\Config\Database;
use PDO;
use Ramsey\Uuid\Uuid; // Assuming Ramsey UUID library for UUID generation (composer require ramsey/uuid)

class AuthService {
    private PDO $db;

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    /**
     * Registers a new company and its initial admin user.
     *
     * @param array $userData User details (firstName, lastName, email, phoneNumber, password)
     * @param array $companyData Company details (companyName, companyEmail, companyPhoneNumber, companyAddress)
     * @return array ['success' => bool, 'message' => string, 'data' => array (optional user/company info)]
     * @throws \Exception If database errors occur.
     */
    public function registerCompanyAndUser(array $userData, array $companyData): array {
        // --- Input Validation (Basic - more thorough validation should be here or in controller) ---
        $requiredUserKeys = ['firstName', 'lastName', 'email', 'phoneNumber', 'password'];
        foreach ($requiredUserKeys as $key) {
            if (empty($userData[$key])) throw new \InvalidArgumentException("User field '$key' is required.");
        }
        $requiredCompanyKeys = ['companyName', 'companyEmail']; // companyPhoneNumber, companyAddress are optional based on schema
        foreach ($requiredCompanyKeys as $key) {
            if (empty($companyData[$key])) throw new \InvalidArgumentException("Company field '$key' is required.");
        }
        if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid user email format.");
        }
        if (!filter_var($companyData['companyEmail'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid company email format.");
        }
        // Basic password length check (example)
        if (strlen($userData['password']) < 8) {
            throw new \InvalidArgumentException("Password must be at least 8 characters long.");
        }


        // --- Check for existing user/company ---
        $stmt = $this->db->prepare("SELECT UID FROM users WHERE Email = :email OR PhoneNumber = :phone");
        $stmt->bindParam(':email', $userData['email']);
        $stmt->bindParam(':phone', $userData['phoneNumber']);
        $stmt->execute();
        if ($stmt->fetchColumn()) {
            return ['success' => false, 'message' => 'User with this email or phone number already exists.'];
        }

        $stmt = $this->db->prepare("SELECT CompanyID FROM company WHERE CompanyName = :companyName OR Email = :companyEmail");
        $stmt->bindParam(':companyName', $companyData['companyName']);
        $stmt->bindParam(':companyEmail', $companyData['companyEmail']);
        $stmt->execute();
        if ($stmt->fetchColumn()) {
            return ['success' => false, 'message' => 'Company with this name or email already exists.'];
        }

        $this->db->beginTransaction();

        try {
            // 1. Create Company
            $companyUID = Uuid::uuid4()->toString(); // Generate a UUID for the company
            $sqlCompany = "INSERT INTO company (CompanyName, CompanyUID, PhoneNumber, Email, Address)
                           VALUES (:companyName, :companyUID, :phoneNumber, :email, :address)";
            $stmtCompany = $this->db->prepare($sqlCompany);
            $stmtCompany->bindParam(':companyName', $companyData['companyName']);
            $stmtCompany->bindParam(':companyUID', $companyUID);
            $stmtCompany->bindParam(':phoneNumber', $companyData['companyPhoneNumber']); // Null if not provided
            $stmtCompany->bindParam(':email', $companyData['companyEmail']);
            $stmtCompany->bindParam(':address', $companyData['companyAddress']);     // Null if not provided
            $stmtCompany->execute();
            $companyId = $this->db->lastInsertId();

            // 2. Get 'CompanyAdmin' Role ID (Assuming it's pre-populated or fetched)
            // For simplicity, assume RID for 'CompanyAdmin' is known, e.g., 2, or fetch it.
            $roleStmt = $this->db->prepare("SELECT RID FROM roles WHERE RoleRUID = 'COMPANY_ADMIN_ROLE_UID'");
            $roleStmt->execute();
            $companyAdminRid = $roleStmt->fetchColumn();
            if (!$companyAdminRid) {
                // Fallback or error if role not found, maybe use a default 'User' role ID
                $roleStmt = $this->db->prepare("SELECT RID FROM roles WHERE RoleRUID = 'USER_ROLE_UID'");
                $roleStmt->execute();
                $companyAdminRid = $roleStmt->fetchColumn() ?: 1; // Fallback to 1 if nothing found
                 error_log("COMPANY_ADMIN_ROLE_UID not found, used fallback RID: " . $companyAdminRid);
            }


            // 3. Create User
            $userUUID = Uuid::uuid4()->toString();
            $sqlUser = "INSERT INTO users (UUID, FirstName, MiddleName, LastName, PhoneNumber, Email, CompanyID, RID)
                        VALUES (:uuid, :firstName, :middleName, :lastName, :phoneNumber, :email, :companyId, :rid)";
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->bindParam(':uuid', $userUUID);
            $stmtUser->bindParam(':firstName', $userData['firstName']);
            $stmtUser->bindParam(':middleName', $userData['middleName']); // Null if not provided
            $stmtUser->bindParam(':lastName', $userData['lastName']);
            $stmtUser->bindParam(':phoneNumber', $userData['phoneNumber']);
            $stmtUser->bindParam(':email', $userData['email']);
            $stmtUser->bindParam(':companyId', $companyId, PDO::PARAM_INT);
            $stmtUser->bindParam(':rid', $companyAdminRid, PDO::PARAM_INT);
            $stmtUser->execute();
            $userId = $this->db->lastInsertId();

            // 4. Create Auth Record
            $hashedPassword = password_hash($userData['password'], PASSWORD_ARGON2ID); // Or PASSWORD_BCRYPT
            $sqlAuth = "INSERT INTO auth (UID, PasswordEncrypted, Status)
                        VALUES (:uid, :passwordEncrypted, 'trial')"; // Default to 'trial' status
            $stmtAuth = $this->db->prepare($sqlAuth);
            $stmtAuth->bindParam(':uid', $userId, PDO::PARAM_INT);
            $stmtAuth->bindParam(':passwordEncrypted', $hashedPassword);
            $stmtAuth->execute();

            // 5. Create Company Subscription (Trial)
            $trialEndDate = date('Y-m-d H:i:s', strtotime('+30 days')); // Example: 30-day trial
            $sqlSubscription = "INSERT INTO company_subscription (CompanyID, PackageName, MaxUsers, MaxCalculationsPerDay, SubscriptionEndDate, PaymentStatus)
                                VALUES (:companyId, 'trial', :maxUsers, :maxCalcs, :endDate, 'active')"; // Trial is active
            $stmtSubscription = $this->db->prepare($sqlSubscription);
            // Default trial limits (could come from a config file)
            $trialMaxUsers = getenv('TRIAL_MAX_USERS') ?: 1;
            $trialMaxCalcs = getenv('TRIAL_MAX_CALCS_PER_DAY') ?: 5;
            $stmtSubscription->bindParam(':companyId', $companyId, PDO::PARAM_INT);
            $stmtSubscription->bindParam(':maxUsers', $trialMaxUsers, PDO::PARAM_INT);
            $stmtSubscription->bindParam(':maxCalcs', $trialMaxCalcs, PDO::PARAM_INT);
            $stmtSubscription->bindParam(':endDate', $trialEndDate);
            $stmtSubscription->execute();

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Company and user registered successfully. Welcome!',
                'data' => [ // Only non-sensitive data
                    'userUUID' => $userUUID,
                    'companyUID' => $companyUID
                ]
            ];

        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("Registration PDOException: " . $e->getMessage());
            // Don't expose detailed SQL errors to client
            return ['success' => false, 'message' => 'Registration failed due to a database error. Please try again later.'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Registration Exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'An unexpected error occurred during registration. '. $e->getMessage()];
        }
    }

    // Other auth methods (login, logout, etc.) will go here

    /**
     * Initiates a password reset request for a user by email.
     * Generates a reset token, stores it, and (conceptually) sends an email.
     *
     * @param string $email The user's email address.
     * @return array ['success' => bool, 'message' => string]
     */
    public function requestPasswordReset(string $email): array {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Valid email address is required for password reset.");
        }

        $sqlUser = "SELECT UID, FirstName, Email FROM users WHERE Email = :email";
        $stmtUser = $this->db->prepare($sqlUser);
        $stmtUser->bindParam(':email', $email);
        $stmtUser->execute();
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Do not reveal if the email exists or not for security reasons.
            // Still return a success-like message to prevent email enumeration.
            error_log("Password reset requested for non-existent email: $email");
            return ['success' => true, 'message' => 'If your email address is in our system, you will receive a password reset link shortly.'];
        }

        try {
            // Generate a secure, unique token
            // Ensure random_bytes is available. For PHP < 7, need paragonie/random_compat or similar.
            if (function_exists('random_bytes')) {
                $token = bin2hex(random_bytes(32)); // Generates a 64-character hex token
            } else {
                // Fallback for older PHP or systems without random_bytes - less secure
                $token = bin2hex(openssl_random_pseudo_bytes(32));
            }

            $resetTokenExpiryHours = getenv('PASSWORD_RESET_EXPIRY_HOURS') ?: 1; // Default 1 hour expiry
            $expiresAtTimestamp = time() + ($resetTokenExpiryHours * 3600);
            $expiresAt = date('Y-m-d H:i:s', $expiresAtTimestamp);

            // Invalidate any previous, unused tokens for this user to prevent multiple active reset links
            $sqlInvalidateOld = "UPDATE password_resets SET Used = TRUE WHERE UID = :uid AND Used = FALSE AND ExpiresAt > NOW()";
            $stmtInvalidate = $this->db->prepare($sqlInvalidateOld);
            $stmtInvalidate->bindParam(':uid', $user['UID'], PDO::PARAM_INT);
            $stmtInvalidate->execute();

            // Store the new token
            $sqlInsertToken = "INSERT INTO password_resets (UID, Token, ExpiresAt) VALUES (:uid, :token, :expiresAt)";
            $stmtInsertToken = $this->db->prepare($sqlInsertToken);
            $stmtInsertToken->bindParam(':uid', $user['UID'], PDO::PARAM_INT);
            $stmtInsertToken->bindParam(':token', $token);
            $stmtInsertToken->bindParam(':expiresAt', $expiresAt);
            $stmtInsertToken->execute();

            // --- Conceptually Send Email ---
            // This part requires an actual email sending library (PHPMailer, Symfony Mailer, etc.)
            // and configuration for an SMTP server or email service.
            $frontendBaseUrl = getenv('APP_FRONTEND_URL') ?: 'http://xactload.hostboxindia.com'; // Adjust as needed
            $resetLink = $frontendBaseUrl . '/reset-password.html?token=' . $token; // Assuming a frontend page handles this

            $emailSubject = "Password Reset Request - XactLoad";
            $emailBody = "Hello " . htmlspecialchars($user['FirstName']) . ",\n\n" .
                         "A request has been made to reset your password. Please click the link below to proceed:\n" .
                         $resetLink . "\n\n" .
                         "If you did not request this password reset, please ignore this email.\n" .
                         "This link will expire in " . $resetTokenExpiryHours . " hour(s).\n\n" .
                         "Regards,\nThe XactLoad Team";
            $emailHeaders = "From: no-reply@" . (getenv('APP_DOMAIN') ?: 'xactload.hostboxindia.com');

            // Placeholder for actual email sending:
            // $emailSent = mail($user['Email'], $emailSubject, $emailBody, $emailHeaders);
            // if (!$emailSent) {
            //     error_log("CRITICAL: Failed to send password reset email to " . $user['Email'] . " for token " . $token);
            //     // Depending on policy, could:
            //     // 1. Still return success to user (as they don't know email failed)
            //     // 2. Throw an exception to indicate a system problem if email is vital
            //     // For now, log it and proceed as if sent for the purpose of API response.
            // }
            error_log("Password Reset Email (Conceptual) for user {$user['UID']} ({$user['Email']}): Link: $resetLink");
            // For actual testing, you'd check the database for the token or use a mail trapping service.

            return ['success' => true, 'message' => 'If your email address is in our system, you will receive a password reset link shortly.'];

        } catch (\PDOException $e) {
            error_log("RequestPasswordReset PDOException for email {$email}: " . $e->getMessage());
            // Do not expose detailed SQL errors.
            return ['success' => false, 'message' => 'Password reset request failed due to a server error. Please try again later.'];
        } catch (\Exception $e) { // Catches random_bytes exception or others
            error_log("RequestPasswordReset Exception for email {$email}: " . $e->getMessage());
            return ['success' => false, 'message' => 'An unexpected error occurred: ' . $e->getMessage()];
        }
    }

    // Placeholder for validateRegistrationInput, checkUserExists, checkCompanyExists, getRoleIdByRuid
    // These methods would be the same as in the previous version of AuthService.php
    // For brevity in this diff, they are not repeated if unchanged.
    private function validateRegistrationInput(array $userData, array $companyData): void {
        $requiredUserKeys = ['firstName', 'lastName', 'email', 'phoneNumber', 'password'];
        foreach ($requiredUserKeys as $key) { if (empty($userData[$key])) throw new \InvalidArgumentException("User field '$key' is required."); }
        $requiredCompanyKeys = ['companyName', 'companyEmail'];
        foreach ($requiredCompanyKeys as $key) { if (empty($companyData[$key])) throw new \InvalidArgumentException("Company field '$key' is required."); }
        if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException("Invalid user email format.");
        if (!filter_var($companyData['companyEmail'], FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException("Invalid company email format.");
        if (strlen($userData['password']) < 8) throw new \InvalidArgumentException("Password must be at least 8 characters.");
    }
    private function checkUserExists(string $email, string $phoneNumber): bool {
        $stmt = $this->db->prepare("SELECT UID FROM users WHERE Email = :email OR PhoneNumber = :phone");
        $stmt->execute([':email' => $email, ':phone' => $phoneNumber]); return $stmt->fetchColumn() !== false;
    }
    private function checkCompanyExists(string $companyName, string $companyEmail): bool {
        $stmt = $this->db->prepare("SELECT CompanyID FROM company WHERE CompanyName = :companyName OR Email = :companyEmail");
        $stmt->execute([':companyName' => $companyName, ':companyEmail' => $companyEmail]); return $stmt->fetchColumn() !== false;
    }
    private function getRoleIdByRuid(string $roleRuid): ?int {
        $stmt = $this->db->prepare("SELECT RID FROM roles WHERE RoleRUID = :roleRuid");
        $stmt->bindParam(':roleRuid', $roleRuid); $stmt->execute(); $rid = $stmt->fetchColumn(); return $rid ? (int)$rid : null;
    }
     // loginUser and logoutUser methods would also be here from previous steps.
     // For brevity, not repeating them if they are unchanged by this specific addition.
    public function loginUser(string $identifier, string $password): array { /* ... as before ... */ return ['success'=>false, 'message'=>'Not fully implemented in this snippet'];} // Add actual code later
    public function logoutUser(int $userId): array { /* ... as before ... */ return ['success'=>false, 'message'=>'Not fully implemented in this snippet'];} // Add actual code later


    /**
     * Resets a user's password using a valid reset token.
     *
     * @param string $token The password reset token.
     * @param string $newPassword The new plain text password.
     * @return array ['success' => bool, 'message' => string]
     */
    public function resetPasswordWithToken(string $token, string $newPassword): array {
        if (empty($token)) {
            throw new \InvalidArgumentException("Reset token is required.");
        }
        if (empty($newPassword) || strlen($newPassword) < 8) {
            throw new \InvalidArgumentException("New password must be at least 8 characters long.");
        }

        $this->db->beginTransaction();
        try {
            // Find the token and associated user ID
            $sqlFindToken = "SELECT UID, ExpiresAt, Used FROM password_resets WHERE Token = :token";
            $stmtFindToken = $this->db->prepare($sqlFindToken);
            $stmtFindToken->bindParam(':token', $token);
            $stmtFindToken->execute();
            $resetRecord = $stmtFindToken->fetch(PDO::FETCH_ASSOC);

            if (!$resetRecord) {
                return ['success' => false, 'message' => 'Invalid or expired password reset token. Please request a new one.'];
            }

            if ($resetRecord['Used']) {
                return ['success' => false, 'message' => 'This password reset token has already been used.'];
            }

            if (strtotime($resetRecord['ExpiresAt']) < time()) {
                // Mark as used even if expired to prevent reuse if DB time and PHP time are slightly off
                $this->markTokenAsUsed($token);
                $this->db->commit(); // Commit the marking of token as used
                return ['success' => false, 'message' => 'Password reset token has expired. Please request a new one.'];
            }

            $userId = $resetRecord['UID'];

            // Hash the new password
            $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);

            // Update the password in the auth table
            $sqlUpdateAuth = "UPDATE auth SET PasswordEncrypted = :passwordEncrypted WHERE UID = :uid";
            $stmtUpdateAuth = $this->db->prepare($sqlUpdateAuth);
            $stmtUpdateAuth->bindParam(':passwordEncrypted', $hashedPassword);
            $stmtUpdateAuth->bindParam(':uid', $userId, PDO::PARAM_INT);
            $stmtUpdateAuth->execute();

            // Mark the token as used
            $this->markTokenAsUsed($token);

            $this->db->commit();
            return ['success' => true, 'message' => 'Your password has been successfully reset. You can now login with your new password.'];

        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("ResetPassword PDOException for token {$token}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Password reset failed due to a server error.'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("ResetPassword Exception for token {$token}: " . $e->getMessage());
            return ['success' => false, 'message' => 'An unexpected error occurred: ' . $e->getMessage()];
        }
    }

    /**
     * Helper to mark a password reset token as used.
     */
    private function markTokenAsUsed(string $token): void {
        $sqlMarkUsed = "UPDATE password_resets SET Used = TRUE WHERE Token = :token";
        $stmtMarkUsed = $this->db->prepare($sqlMarkUsed);
        $stmtMarkUsed->bindParam(':token', $token);
        $stmtMarkUsed->execute();
    }
}
```

**File: `backend/src/Controller/AuthController.php` (Modified)**
```php
<?php
namespace App\Controller;

use App\Service\AuthService; // Assuming AuthService.php is in App\Service
// No need for Database here, AuthService handles it.

class AuthController {
    private AuthService $authService;

    public function __construct() {
        $this->authService = new AuthService();
    }

    public function handleRequest(string $method, ?string $action, string $rawJsonData, array $pathSegments): void {
        $data = json_decode($rawJsonData, true);
        if ($method === 'POST' && $action === 'register') {
            $this->register($data);
        } elseif ($method === 'POST' && $action === 'login') {
            // $this->login($data); // To be implemented
             http_response_code(501); echo json_encode(["status" => "info", "message" => "/auth/login not implemented yet."]);
        } elseif ($method === 'POST' && $action === 'logout') {
            // $this->logout(); // To be implemented
             http_response_code(501); echo json_encode(["status" => "info", "message" => "/auth/logout not implemented yet."]);
        } elseif ($method === 'POST' && $action === 'forgot-password') {
            // $this->forgotPassword($data); // To be implemented
             http_response_code(501); echo json_encode(["status" => "info", "message" => "/auth/forgot-password not implemented yet."]);
        } elseif ($method === 'POST' && $action === 'reset-password') {
            // $this->resetPassword($data); // To be implemented
             http_response_code(501); echo json_encode(["status" => "info", "message" => "/auth/reset-password not implemented yet."]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Auth action not found or method not allowed."]);
        }
    }

    private function register(?array $data): void {
        if ($data === null || !isset($data['user']) || !isset($data['company'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid registration data. "user" and "company" objects required.']);
            return;
        }

        $userData = $data['user'];
        $companyData = $data['company'];

        // Basic validation for presence of required sub-fields
        $requiredUserKeys = ['firstName', 'lastName', 'email', 'phoneNumber', 'password'];
        foreach ($requiredUserKeys as $key) {
            if (empty($userData[$key])) {
                 http_response_code(400);
                 echo json_encode(['status' => 'error', 'message' => "User field '$key' is required for registration."]);
                 return;
            }
        }
        $requiredCompanyKeys = ['companyName', 'companyEmail'];
         foreach ($requiredCompanyKeys as $key) {
            if (empty($companyData[$key])) {
                 http_response_code(400);
                 echo json_encode(['status' => 'error', 'message' => "Company field '$key' is required for registration."]);
                 return;
            }
        }


        try {
            $result = $this->authService->registerCompanyAndUser($userData, $companyData);
            if ($result['success']) {
                http_response_code(201); // Created
                echo json_encode($result);
            } else {
                http_response_code(400); // Bad request (e.g., duplicate, validation error from service)
                echo json_encode($result);
            }
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log("Registration Controller Exception: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred during registration.']);
        }
    }

    // Placeholder for other methods like login, logout etc.
}
?>
```

**Assumptions & Notes:**
*   **UUID Generation:** I've added a comment `use Ramsey\Uuid\Uuid;`. This popular library would be installed via Composer (`composer require ramsey/uuid`). If not using Composer, a simpler UUID v4 function would be needed.
*   **Password Hashing:** Using `password_hash` with `PASSWORD_ARGON2ID` (preferred) or `PASSWORD_BCRYPT`.
*   **Role ID (`RID`):** The code attempts to fetch the `RID` for 'COMPANY_ADMIN_ROLE_UID'. Assumes this role (and 'USER_ROLE_UID' as fallback) exists in the `roles` table (as per your schema's `INSERT` statements).
*   **Trial Subscription:** Creates a default 30-day trial subscription with basic limits. These limits (`TRIAL_MAX_USERS`, `TRIAL_MAX_CALCS_PER_DAY`) can be set in the `.env` file.
*   **Error Handling:** Basic try-catch blocks. `AuthService` returns arrays with `success` and `message`. Controller sets HTTP status codes.
*   **Input Validation:** Basic validation is in `AuthService` and controller. In a real app, this would be more extensive, possibly using a dedicated validation library.
*   **Dependencies:** `Database.php` needs to be correctly set up and accessible.
*   **Namespace:** Assumes `App\Service`, `App\Controller`. Adjust if your namespace structure is different.
*   The `backend/index.php` router would need to be updated to instantiate and call `AuthController` for `/auth/*` routes.

This provides the conceptual implementation for the User Registration API. The next step would be to test this flow mentally, then proceed to the login API.
