```php
<?php

namespace App\Service;

use App\Config\Database;
use PDO;
use Ramsey\Uuid\Uuid;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService {
    private PDO $db;
    private string $jwtSecretKey;
    private string $jwtIssuer;
    private int $jwtExpirationSeconds;
    private const EPSILON = 0.01;


    public function __construct(?PDO $dbConnection = null) {
        $this->db = $dbConnection ?? (new Database())->getConnection();
        $this->jwtSecretKey = getenv('JWT_SECRET_KEY') ?: 'fallback_secret_6789012345_abcde_1234567890_xyz_0987654321_top_secret';
        $this->jwtIssuer = getenv('JWT_ISSUER') ?: 'xactload.hostboxindia.com';
        $this->jwtExpirationSeconds = (int)(getenv('JWT_EXPIRATION_SECONDS') ?: 3600);
    }

    public function registerCompanyAndUser(array $userData, array $companyData): array {
        $this->validateRegistrationInput($userData, $companyData);
        if ($this->checkUserExists($userData['email'], $userData['phoneNumber'])) {
            return ['success' => false, 'message' => 'User with this email or phone number already exists.'];
        }
        if ($this->checkCompanyExists($companyData['companyName'], $companyData['companyEmail'])) {
            return ['success' => false, 'message' => 'Company with this name or email already exists.'];
        }
        $this->db->beginTransaction();
        try {
            $companyUID = Uuid::uuid4()->toString();
            $sqlCompany = "INSERT INTO company (CompanyName, CompanyUID, PhoneNumber, Email, Address)
                           VALUES (:companyName, :companyUID, :phoneNumber, :email, :address)";
            $stmtCompany = $this->db->prepare($sqlCompany);
            $stmtCompany->execute([
                ':companyName' => $companyData['companyName'], ':companyUID' => $companyUID,
                ':phoneNumber' => $companyData['companyPhoneNumber'] ?? null,
                ':email' => $companyData['companyEmail'], ':address' => $companyData['companyAddress'] ?? null
            ]);
            $companyId = $this->db->lastInsertId();

            $roleRuidToAssign = 'COMPANY_ADMIN_ROLE_UID'; // Default for new company registration
            $companyAdminRid = $this->getRoleIdByRuid($roleRuidToAssign);
            if (!$companyAdminRid) {
                 $companyAdminRid = $this->getRoleIdByRuid('USER_ROLE_UID');
                 if(!$companyAdminRid) throw new \Exception("Default roles ('COMPANY_ADMIN_ROLE_UID' or 'USER_ROLE_UID') not found in database.");
                 error_log("Role '$roleRuidToAssign' not found, used fallback USER_ROLE_UID: " . $companyAdminRid);
            }

            $userUUID = Uuid::uuid4()->toString();
            $sqlUser = "INSERT INTO users (UUID, FirstName, MiddleName, LastName, PhoneNumber, Email, CompanyID, RID)
                        VALUES (:uuid, :firstName, :middleName, :lastName, :phoneNumber, :email, :companyId, :rid)";
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute([
                ':uuid' => $userUUID, ':firstName' => $userData['firstName'],
                ':middleName' => $userData['middleName'] ?? null, ':lastName' => $userData['lastName'],
                ':phoneNumber' => $userData['phoneNumber'], ':email' => $userData['email'],
                ':companyId' => $companyId, ':rid' => $companyAdminRid
            ]);
            $userId = $this->db->lastInsertId();

            $hashedPassword = password_hash($userData['password'], PASSWORD_ARGON2ID);
            $sqlAuth = "INSERT INTO auth (UID, PasswordEncrypted, Status) VALUES (:uid, :passwordEncrypted, 'trial')";
            $stmtAuth = $this->db->prepare($sqlAuth);
            $stmtAuth->execute([':uid' => $userId, ':passwordEncrypted' => $hashedPassword]);

            $trialEndDate = date('Y-m-d H:i:s', strtotime('+'. (getenv('TRIAL_DURATION_DAYS') ?: 30) .' days'));
            $trialMaxUsers = getenv('TRIAL_MAX_USERS') ?: 1; $trialMaxCalcs = getenv('TRIAL_MAX_CALCS_PER_DAY') ?: 5;
            $sqlSubscription = "INSERT INTO company_subscription
                                (CompanyID, PackageName, MaxUsers, MaxCalculationsPerDay, SubscriptionEndDate, PaymentStatus)
                                VALUES (:companyId, 'trial', :maxUsers, :maxCalcs, :endDate, 'active')";
            $stmtSubscription = $this->db->prepare($sqlSubscription);
            $stmtSubscription->execute([':companyId' => $companyId, ':maxUsers' => (int)$trialMaxUsers, ':maxCalcs' => (int)$trialMaxCalcs, ':endDate' => $trialEndDate]);

            $this->db->commit();
            return ['success' => true, 'message' => 'Company and user registered successfully.',
                    'data' => ['userUUID' => $userUUID, 'companyUID' => $companyUID]];
        } catch (\PDOException $e) { $this->db->rollBack(); error_log("Reg PDO: " . $e->getMessage()); return ['success' => false, 'message' => 'DB error during registration.'];
        } catch (\Exception $e) { $this->db->rollBack(); error_log("Reg Ex: " . $e->getMessage()); return ['success' => false, 'message' => 'Error: ' . $e->getMessage()]; }
    }

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

    public function loginUser(string $identifier, string $password): array {
        if (empty($identifier) || empty($password)) {
            throw new \InvalidArgumentException("Identifier and password are required.");
        }
        $sql = "SELECT u.UID, u.UUID, u.FirstName, u.LastName, u.Email, u.PhoneNumber, u.CompanyID, a.PasswordEncrypted, a.Status, r.RoleRUID, c.CompanyUID, c.CompanyName
                FROM users u
                JOIN auth a ON u.UID = a.UID JOIN roles r ON u.RID = r.RID JOIN company c ON u.CompanyID = c.CompanyID
                WHERE u.Email = :identifier OR u.PhoneNumber = :identifier";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':identifier', $identifier); $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['PasswordEncrypted'])) {
            return ['success' => false, 'message' => 'Invalid credentials or user not found.'];
        }
        if ($user['Status'] === 'inactive' || $user['Status'] === 'suspended') {
            return ['success' => false, 'message' => 'Account is ' . $user['Status'] . '. Contact support.'];
        }

        try {
            $updateSql = "UPDATE auth SET LastLogin = CURRENT_TIMESTAMP, LoggedInStatus = TRUE WHERE UID = :uid";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->bindParam(':uid', $user['UID'], PDO::PARAM_INT); $updateStmt->execute();

            $issuedAt = time(); $expire = $issuedAt + $this->jwtExpirationSeconds;
            $payload = [
                'iss' => $this->jwtIssuer, 'aud' => $this->jwtIssuer, 'iat' => $issuedAt, 'nbf' => $issuedAt, 'exp' => $expire,
                'data' => [
                    'userId' => $user['UID'], 'userUUID' => $user['UUID'],
                    'companyId' => $user['CompanyID'], 'companyUID' => $user['CompanyUID'], 'companyName' => $user['CompanyName'],
                    'roleRUID' => $user['RoleRUID'], 'email' => $user['Email'], 'name' => $user['FirstName'] . ' ' . $user['LastName']
                ]
            ];
            $token = JWT::encode($payload, $this->jwtSecretKey, 'HS256');
            return ['success' => true, 'message' => 'Login successful.', 'data' => [
                'token' => $token, 'expiresIn' => $this->jwtExpirationSeconds,
                'user' => [
                    'uuid' => $user['UUID'], 'firstName' => $user['FirstName'], 'lastName' => $user['LastName'],
                    'email' => $user['Email'], 'phoneNumber' => $user['PhoneNumber'],
                    'roleRUID' => $user['RoleRUID'], 'companyUID' => $user['CompanyUID'], 'companyName' => $user['CompanyName']
                ]]];
        } catch (\PDOException $e) { error_log("Login PDO: " . $e->getMessage()); return ['success' => false, 'message' => 'DB error during login.'];
        } catch (\Exception $e) { error_log("Login Ex: " . $e->getMessage()); return ['success' => false, 'message' => 'Error during login: ' . $e->getMessage()];}
    }

    public function logoutUser(int $userId): array {
        if (empty($userId)) { return ['success' => false, 'message' => 'User ID not provided for logout.']; }
        try {
            $sql = "UPDATE auth SET LoggedInStatus = FALSE WHERE UID = :uid";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':uid', $userId, PDO::PARAM_INT); $stmt->execute();
            return ['success' => true, 'message' => 'Logout successful. Please discard your token.'];
        } catch (\PDOException $e) { error_log("Logout PDO: " . $e->getMessage()); return ['success' => false, 'message' => 'DB error during logout.'];
        } catch (\Exception $e) { error_log("Logout Ex: " . $e->getMessage()); return ['success' => false, 'message' => 'Error during logout: ' . $e->getMessage()];}
    }

    public function requestPasswordReset(string $email): array {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Valid email address is required for password reset.");
        }
        $sqlUser = "SELECT UID, FirstName, Email FROM users WHERE Email = :email";
        $stmtUser = $this->db->prepare($sqlUser); $stmtUser->bindParam(':email', $email); $stmtUser->execute();
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log("Password reset requested for non-existent email: $email");
            return ['success' => true, 'message' => 'If your email address is in our system, you will receive a password reset link shortly.'];
        }
        try {
            $token = bin2hex(random_bytes(32));
            $resetTokenExpiryHours = getenv('PASSWORD_RESET_EXPIRY_HOURS') ?: 1;
            $expiresAt = date('Y-m-d H:i:s', time() + ($resetTokenExpiryHours * 3600));

            $sqlInvalidateOld = "UPDATE password_resets SET Used = TRUE WHERE UID = :uid AND Used = FALSE AND ExpiresAt > NOW()";
            $stmtInvalidate = $this->db->prepare($sqlInvalidateOld);
            $stmtInvalidate->bindParam(':uid', $user['UID'], PDO::PARAM_INT); $stmtInvalidate->execute();

            $sqlInsertToken = "INSERT INTO password_resets (UID, Token, ExpiresAt) VALUES (:uid, :token, :expiresAt)";
            $stmtInsertToken = $this->db->prepare($sqlInsertToken);
            $stmtInsertToken->execute([':uid' => $user['UID'], ':token' => $token, ':expiresAt' => $expiresAt]);

            // Conceptual Email Sending
            $frontendBaseUrl = getenv('APP_FRONTEND_URL') ?: 'http://xactload.hostboxindia.com';
            $resetLink = $frontendBaseUrl . '/reset-password.html?token=' . $token;
            error_log("Password Reset Email (Conceptual) for user {$user['UID']} ({$user['Email']}): Link: $resetLink");

            return ['success' => true, 'message' => 'If your email address is in our system, you will receive a password reset link shortly.'];
        } catch (\PDOException $e) { error_log("ReqPassReset PDO for {$email}: " . $e->getMessage()); return ['success' => false, 'message' => 'Password reset request failed (DB).'];
        } catch (\Exception $e) { error_log("ReqPassReset Ex for {$email}: " . $e->getMessage()); return ['success' => false, 'message' => 'Unexpected error for password reset.']; }
    }

    public function resetPasswordWithToken(string $token, string $newPassword): array {
        if (empty($token)) throw new \InvalidArgumentException("Reset token is required.");
        if (empty($newPassword) || strlen($newPassword) < 8) throw new \InvalidArgumentException("New password must be at least 8 characters long.");

        $this->db->beginTransaction();
        try {
            $sqlFindToken = "SELECT UID, ExpiresAt, Used FROM password_resets WHERE Token = :token";
            $stmtFindToken = $this->db->prepare($sqlFindToken); $stmtFindToken->bindParam(':token', $token); $stmtFindToken->execute();
            $resetRecord = $stmtFindToken->fetch(PDO::FETCH_ASSOC);

            if (!$resetRecord) return ['success' => false, 'message' => 'Invalid or expired password reset token.'];
            if ($resetRecord['Used']) return ['success' => false, 'message' => 'This token has already been used.'];
            if (strtotime($resetRecord['ExpiresAt']) < time()) {
                $this->markTokenAsUsed($token); $this->db->commit();
                return ['success' => false, 'message' => 'Password reset token has expired.'];
            }
            $userId = $resetRecord['UID'];
            $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);
            $sqlUpdateAuth = "UPDATE auth SET PasswordEncrypted = :passwordEncrypted WHERE UID = :uid";
            $stmtUpdateAuth = $this->db->prepare($sqlUpdateAuth);
            $stmtUpdateAuth->execute([':passwordEncrypted' => $hashedPassword, ':uid' => $userId]);
            $this->markTokenAsUsed($token);
            $this->db->commit();
            return ['success' => true, 'message' => 'Password has been reset. You can now login.'];
        } catch (\PDOException $e) { $this->db->rollBack(); error_log("ResetPass PDO for token {$token}: " . $e->getMessage()); return ['success' => false, 'message' => 'Password reset failed (DB).'];
        } catch (\Exception $e) { $this->db->rollBack(); error_log("ResetPass Ex for token {$token}: " . $e->getMessage()); return ['success' => false, 'message' => 'Unexpected error during password reset.']; }
    }

    private function markTokenAsUsed(string $token): void {
        $sqlMarkUsed = "UPDATE password_resets SET Used = TRUE WHERE Token = :token";
        $stmtMarkUsed = $this->db->prepare($sqlMarkUsed);
        $stmtMarkUsed->bindParam(':token', $token); $stmtMarkUsed->execute();
    }
}
```
